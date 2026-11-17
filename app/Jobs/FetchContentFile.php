<?php

namespace App\Jobs;

use App\Catalog\Providers\ProviderUnavailable;
use App\Enums\AccessType;
use App\Enums\ContentStatus;
use App\Models\ContentSource;
use App\Reader\Archives\EpubInspector;
use App\Reader\Archives\UnsafeArchive;
use App\Reader\ContentFetchRefused;
use finfo;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

/**
 * Downloads the file of a verified public-domain source from its provider and keeps it on the private reader disk. Everything about the file
 * is checked before it is kept: the host (only the provider's own), the size, the type by content (not by name), the archive guard and the EPUB
 * layout. Gutenberg's own record of the book is read first, because a few Gutenberg books are still under copyright (P9-R7). A refusal marks the
 * source `failed` with a short reason and stores nothing; a provider outage is retried, and re-saving the source on the admin page starts over.
 */
class FetchContentFile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $contentSourceId)
    {
        $this->onQueue('ingestion');
    }

    /**
     * One download per source at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->contentSourceId;
    }

    /**
     * Only a provider outage is retried, with growing pauses; a refused file never is.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $source = ContentSource::find($this->contentSourceId);

        if ($source === null || ! $this->wanted($source)) {
            return;
        }

        $temp = tempnam(sys_get_temp_dir(), 'bookflix-fetch-');

        if ($temp === false) {
            throw new ProviderUnavailable('No temporary file could be made.');
        }

        try {
            $this->fetch($source, $temp);
        } catch (ContentFetchRefused|UnsafeArchive $refusal) {
            $this->fail($source, $refusal->getMessage());
        } finally {
            @unlink($temp);
        }
    }

    /**
     * The retries are spent: leave the source failed so the admin sees it and can save it again.
     */
    public function failed(Throwable $exception): void
    {
        $source = ContentSource::find($this->contentSourceId);

        if ($source !== null && $source->status !== ContentStatus::Ready) {
            $this->fail($source, __('The provider could not be reached. Save the source again to retry.'));
        }
    }

    private function wanted(ContentSource $source): bool
    {
        if ($source->access_type !== AccessType::PublicDomain || ! $source->isVerified() || $source->url === null || $source->url === '') {
            return false;
        }

        $present = $source->status === ContentStatus::Ready
            && $source->storage_path !== null
            && Storage::disk(ContentSource::disk())->exists($source->storage_path);

        return ! $present;
    }

    /**
     * @throws ContentFetchRefused
     * @throws UnsafeArchive
     * @throws ProviderUnavailable
     */
    private function fetch(ContentSource $source, string $temp): void
    {
        [$provider, $id] = $this->provider($source);
        $url = $this->allowedUrl($source, $provider);

        if ($provider === 'gutenberg') {
            $this->requirePublicDomain($id);
        }

        $this->download($url, $temp);

        $size = (int) filesize($temp);
        $max = (int) config('bookflix.reader.fetch.max_mb') * 1048576;

        if ($size === 0 || $size > $max) {
            throw new ContentFetchRefused($size === 0 ? __('The file is empty.') : __('The file is too large.'));
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($temp);

        if (! in_array($mime, ['application/epub+zip', 'application/zip'], true)) {
            throw new ContentFetchRefused(__('The file is not an EPUB.'));
        }

        app(EpubInspector::class)->check($temp);

        $path = 'content/pd/'.Str::ulid().'.epub';
        $stream = fopen($temp, 'rb');

        if ($stream === false || ! Storage::disk(ContentSource::disk())->put($path, $stream)) {
            throw new ProviderUnavailable('The file could not be stored.');
        }

        fclose($stream);

        $source->forceFill([
            'storage_path' => $path,
            'byte_size' => $size,
            'checksum' => hash_file('sha256', $temp),
            'mime_type' => 'application/epub+zip',
            'status' => ContentStatus::Ready,
            'failure_reason' => null,
        ])->save();
    }

    /**
     * The provider and book id named by the source's external key (`gutenberg:123`).
     *
     * @return array{0: string, 1: string}
     *
     * @throws ContentFetchRefused
     */
    private function provider(ContentSource $source): array
    {
        [$provider, $id] = array_pad(explode(':', (string) $source->external_key, 2), 2, '');

        if (! in_array($provider, ['gutenberg', 'standardebooks'], true) || $id === '' || ($provider === 'gutenberg' && ! ctype_digit($id))) {
            throw new ContentFetchRefused(__('This source does not name a known provider.'));
        }

        return [$provider, $id];
    }

    /**
     * @throws ContentFetchRefused
     */
    private function allowedUrl(ContentSource $source, string $provider): string
    {
        $url = (string) $source->url;
        $parts = parse_url($url);
        $hosts = (array) config("bookflix.providers.{$provider}.allowed_hosts");

        if (($parts['scheme'] ?? '') !== 'https' || ! in_array(strtolower((string) ($parts['host'] ?? '')), $hosts, true)) {
            throw new ContentFetchRefused(__('The file is not at an address this provider owns.'));
        }

        return $url;
    }

    /**
     * Reads the book's official record and refuses unless it says public domain in the USA.
     *
     * @throws ContentFetchRefused
     * @throws ProviderUnavailable
     */
    private function requirePublicDomain(string $id): void
    {
        $response = $this->send(fn () => Http::withUserAgent((string) config('bookflix.provider_user_agent'))
            ->timeout(30)
            ->retry(0)
            ->get("https://www.gutenberg.org/cache/epub/{$id}/pg{$id}.rdf"));

        if ($response->status() === 404) {
            throw new ContentFetchRefused(__('Project Gutenberg has no record of this book.'));
        }

        $rights = $this->rights($response->body());

        if (! collect($rights)->contains(fn (string $text) => str_contains($text, 'Public domain in the USA'))) {
            throw new ContentFetchRefused(__('Project Gutenberg marks this book as copyrighted.'));
        }
    }

    /**
     * The `dcterms:rights` texts of an RDF record. The parser never loads external entities or the network.
     *
     * @return list<string>
     */
    private function rights(string $rdf): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($rdf, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw new ContentFetchRefused(__('Project Gutenberg\'s record of this book could not be read.'));
        }

        $xml->registerXPathNamespace('dcterms', 'http://purl.org/dc/terms/');
        $found = $xml->xpath('//dcterms:rights') ?: [];

        return array_values(array_map(fn ($node) => trim((string) $node), $found));
    }

    /**
     * Streams the file to `$temp`, stopping at the size cap whether the host declares its length or not. Redirects are not followed, so the
     * file can only come from the address that passed the host check.
     *
     * @throws ContentFetchRefused
     * @throws ProviderUnavailable
     */
    private function download(string $url, string $temp): void
    {
        $max = (int) config('bookflix.reader.fetch.max_mb') * 1048576;
        $tooLarge = new ContentFetchRefused(__('The file is too large.'));

        $response = $this->send(fn () => Http::withUserAgent((string) config('bookflix.provider_user_agent'))
            ->timeout(300)
            ->retry(0)
            ->withOptions([
                'sink' => $temp,
                'allow_redirects' => false,
                'on_headers' => function ($response) use ($max, $tooLarge): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $max) {
                        throw $tooLarge;
                    }
                },
                'progress' => function ($total, $downloaded) use ($max, $tooLarge): void {
                    if ($downloaded > $max) {
                        throw $tooLarge;
                    }
                },
            ])
            ->get($url));

        if ($response->status() === 404 || $response->redirect()) {
            throw new ContentFetchRefused(__('The file is no longer at its address.'));
        }

        // The hook above stops a real download early; this covers a client that did not run it.
        if ((int) $response->header('Content-Length') > $max) {
            throw $tooLarge;
        }
    }

    /**
     * Run a request, turning an unreachable host and a server error into an outage the queue retries.
     *
     * @param  callable(): Response  $request
     *
     * @throws ProviderUnavailable
     */
    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw new ProviderUnavailable($exception->getMessage(), previous: $exception);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new ProviderUnavailable("Provider answered HTTP {$response->status()}.");
        }

        return $response;
    }

    private function fail(ContentSource $source, string $reason): void
    {
        Log::info('Content file refused.', ['content_source' => $source->id, 'reason' => $reason]);

        $source->forceFill(['status' => ContentStatus::Failed, 'failure_reason' => $reason])->save();
    }
}
