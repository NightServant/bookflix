<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a media's own stored cover from our origin, so WebGL can texture it without CORS. Not an open proxy: the URL is never
 * taken from the request, only from the media row, and only https hosts the providers allow are fetched (without following redirects).
 */
class CoverController extends Controller
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

    public function show(Media $media): Response
    {
        $url = $media->cover_url;

        abort_unless(is_string($url) && $this->isAllowed($url), 404);

        $disk = Storage::disk('local');
        $path = 'covers/'.sha1($url);

        if (! $disk->exists($path)) {
            $this->store($disk, $path, $this->fetch($url));
        }

        // The type comes from the stored bytes (verified as a raster image on the way in), never from the upstream header.
        $info = getimagesize($disk->path($path));

        abort_unless($info !== false && in_array($info['mime'], self::MIME_TYPES, true), 404);

        return $disk->response($path, null, [
            'Content-Type' => $info['mime'],
            'Cache-Control' => 'public, max-age=86400, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * Written to a temp name and moved into place, so a concurrent request never streams a half-written file.
     */
    private function store(Filesystem $disk, string $path, string $body): void
    {
        $temp = $path.'.'.Str::random(12).'.tmp';

        $disk->put($temp, $body);
        $disk->move($temp, $path);
    }

    private function isAllowed(string $url): bool
    {
        $parts = filter_var($url, FILTER_VALIDATE_URL) === false ? false : parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && in_array(strtolower($parts['host'] ?? ''), $this->allowedHosts(), true);
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        $hosts = [];

        foreach (config('bookflix.providers', []) as $provider) {
            if (($provider['class'] ?? null) === null) {
                continue;
            }

            array_push($hosts, ...array_values($provider['allowed_hosts'] ?? []));
        }

        return $hosts;
    }

    private function fetch(string $url): string
    {
        try {
            $response = Http::withUserAgent((string) config('bookflix.provider_user_agent'))
                ->connectTimeout(3)
                ->timeout(8)
                ->withoutRedirecting()
                ->accept('image/*')
                ->get($url);
        } catch (ConnectionException) {
            abort(404);
        }

        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        $body = $response->body();

        abort_unless($response->successful() && in_array($type, self::MIME_TYPES, true) && $body !== '' && strlen($body) <= self::MAX_BYTES, 404);

        // The header is the host's claim; the bytes must be a real raster image too (never SVG or HTML under an image type).
        $info = getimagesizefromstring($body);

        abort_unless($info !== false && in_array($info['mime'], self::MIME_TYPES, true), 404);

        return $body;
    }
}
