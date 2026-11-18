<?php

namespace App\Reader;

use App\Models\ContentSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a stored reader file with byte ranges, which pdf.js and the EPUB reader use to read a large file in pieces. The type comes from the verified
 * format of the source, never from the file name or anything the client sent. The file is never a page: its own policy forbids everything and
 * sandboxes it, so even a hostile file opened by address cannot run script.
 */
class ByteRangeResponse
{
    private const CHUNK = 65536;

    public static function make(Request $request, ContentSource $source): Response
    {
        $size = (int) $source->byte_size;
        $disk = Storage::disk(ContentSource::disk());

        $headers = [
            'Accept-Ranges' => 'bytes',
            'Content-Type' => $source->format->mimeType(),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        $range = self::range((string) $request->header('Range'), $size);

        if ($range === false) {
            return new Response('', 416, $headers + ['Content-Range' => "bytes */{$size}"]);
        }

        // A list of several ranges gets the whole file: RFC 9110 lets a server ignore a Range header and answer 200, which is simpler than multipart.
        [$start, $end, $status] = $range ?? [0, $size - 1, 200];

        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) max($length, 0);

        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        if ($request->isMethod('HEAD') || $length <= 0) {
            return new Response('', $status, $headers);
        }

        return new StreamedResponse(function () use ($disk, $source, $start, $length): void {
            $stream = $disk->readStream((string) $source->storage_path);

            if (! is_resource($stream)) {
                return;
            }

            // A stream that cannot seek is read forward to the start instead.
            if ($start > 0 && (! stream_get_meta_data($stream)['seekable'] || fseek($stream, $start) !== 0)) {
                self::skip($stream, $start);
            }

            $left = $length;

            while ($left > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(self::CHUNK, $left));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                $left -= strlen($chunk);
                flush();
            }

            fclose($stream);
        }, $status, $headers);
    }

    /**
     * Reads the Range header: null when there is none to apply (absent, another unit, or several ranges), false when it is malformed or cannot be
     * satisfied, otherwise [start, end, 206].
     *
     * @return array{int, int, int}|false|null
     */
    private static function range(string $header, int $size): array|false|null
    {
        $header = trim($header);

        if ($header === '') {
            return null;
        }

        if (! preg_match('/^bytes=(.+)$/i', $header, $match)) {
            return false;
        }

        $specs = array_map('trim', explode(',', $match[1]));

        foreach ($specs as $spec) {
            if (! preg_match('/^(\d*)-(\d*)$/', $spec, $parts) || ($parts[1] === '' && $parts[2] === '')) {
                return false;
            }
        }

        if (count($specs) > 1) {
            return null;
        }

        if (! preg_match('/^(\d*)-(\d*)$/', $specs[0], $parts)) {
            return false;
        }

        if ($size <= 0) {
            return false;
        }

        if ($parts[1] === '') {
            $suffix = (int) $parts[2];

            return $suffix === 0 ? false : [max($size - $suffix, 0), $size - 1, 206];
        }

        $start = (int) $parts[1];
        $end = $parts[2] === '' ? $size - 1 : min((int) $parts[2], $size - 1);

        if ($start >= $size || ($parts[2] !== '' && (int) $parts[2] < $start)) {
            return false;
        }

        return [$start, $end, 206];
    }

    /**
     * @param  resource  $stream
     */
    private static function skip($stream, int $bytes): void
    {
        while ($bytes > 0 && ! feof($stream)) {
            $read = fread($stream, min(self::CHUNK, $bytes));

            if ($read === false || $read === '') {
                return;
            }

            $bytes -= strlen($read);
        }
    }
}
