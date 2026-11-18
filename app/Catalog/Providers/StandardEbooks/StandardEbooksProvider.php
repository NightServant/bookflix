<?php

namespace App\Catalog\Providers\StandardEbooks;

use App\Catalog\Data\ContentFileOffer;
use App\Catalog\Data\NormalizedCreator;
use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderPage;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\Providers\MetadataProvider;
use App\Catalog\Providers\OffersContentFiles;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Catalog\Providers\SanitizesProviderData;
use App\Catalog\Providers\SendsProviderRequests;
use App\Enums\ContentFormat;
use App\Enums\CreatorRole;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;

/**
 * Standard Ebooks through its public Atom "new releases" feed (the newest few ebooks, one page). The feed is the only thing read; there is no
 * member feed and no page is scraped.
 */
class StandardEbooksProvider implements MetadataProvider, OffersContentFiles
{
    use SanitizesProviderData, SendsProviderRequests;

    private const FEED_URL = 'https://standardebooks.org/feeds/atom/new-releases';

    private const ID_PREFIX = 'https://standardebooks.org/ebooks/';

    private const ATOM = 'http://www.w3.org/2005/Atom';

    private const RECOMMENDED_EPUB = 'Recommended compatible epub';

    public function key(): string
    {
        return 'standardebooks';
    }

    /**
     * The whole feed is one page. It lists only the newest ebooks and importing is idempotent, so the cursor is ignored.
     */
    public function page(?CarbonImmutable $since, ?string $pageToken): ProviderPage
    {
        return new ProviderPage($pageToken === null ? $this->records() : [], null, null);
    }

    public function find(string $externalId): ?ProviderRecord
    {
        foreach ($this->records() as $record) {
            if ($record->externalId === $externalId) {
                return $record;
            }
        }

        return null;
    }

    public function normalize(ProviderRecord $record): NormalizedMedia
    {
        $payload = $record->payload;
        $subjects = $this->strings($payload['subjects'] ?? null);
        $fiction = preg_grep('/(?<!non-)\bfiction\b|^fantasy$/i', $subjects) !== [];

        return new NormalizedMedia(
            title: is_string($payload['title'] ?? null) ? trim($payload['title']) : '',
            mediaTypeSlug: $fiction ? 'novel' : 'nonfiction',
            description: $this->plainText($payload['summary'] ?? null),
            creators: array_map(fn (string $name) => new NormalizedCreator($name, CreatorRole::Author), $this->strings($payload['authors'] ?? null)),
            genres: $subjects,
            language: 'en',
            sourceUrl: $this->safeUrl($record->sourceUrl),
        );
    }

    /**
     * The recommended EPUB the feed links, with the entry's own rights text as the license. Null when the entry links no such file.
     */
    public function contentFile(ProviderRecord $record): ?ContentFileOffer
    {
        $url = $this->safeUrl($record->payload['epub'] ?? null);
        $license = is_string($record->payload['rights'] ?? null) ? trim($record->payload['rights']) : '';

        return $url === null || $license === '' ? null : new ContentFileOffer("standardebooks:{$record->externalId}", $url, ContentFormat::Epub, $license);
    }

    /**
     * @return list<ProviderRecord>
     *
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    private function records(): array
    {
        $body = $this->send(fn () => $this->client()->accept('application/atom+xml')->get(self::FEED_URL))->body();

        $previous = libxml_use_internal_errors(true);

        try {
            // No network and no entity substitution: a feed can never make the server fetch a URL or expand an entity bomb.
            $document = new DOMDocument;
            $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new ProviderUnavailable('The Standard Ebooks feed is not valid XML.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('a', self::ATOM);
        $records = [];

        foreach ($xpath->query('/a:feed/a:entry') ?: [] as $entry) {
            $record = $entry instanceof DOMElement ? $this->record($xpath, $entry) : null;

            if ($record === null) {
                Log::warning('A Standard Ebooks feed entry was skipped because it is malformed.', ['provider' => 'standardebooks']);

                continue;
            }

            $records[] = $record;
        }

        return $records;
    }

    private function record(DOMXPath $xpath, DOMElement $entry): ?ProviderRecord
    {
        $value = fn (string $query): string => trim((string) $xpath->evaluate("string({$query})", $entry));
        $id = $value('a:id');
        $path = str_starts_with($id, self::ID_PREFIX) ? substr($id, strlen(self::ID_PREFIX)) : '';

        if (! preg_match('#^[a-z0-9][a-z0-9._-]*(/[a-z0-9][a-z0-9._-]*)+$#i', $path) || $value('a:title') === '') {
            return null;
        }

        $epub = null;

        foreach ($xpath->query('a:link[@rel="enclosure"]', $entry) ?: [] as $link) {
            if ($link instanceof DOMElement && $link->getAttribute('title') === self::RECOMMENDED_EPUB && $link->getAttribute('type') === 'application/epub+zip') {
                $epub = $link->getAttribute('href');
                break;
            }
        }

        $texts = function (string $query) use ($xpath, $entry): array {
            $found = [];

            foreach ($xpath->query($query, $entry) ?: [] as $node) {
                if (trim((string) $node->nodeValue) !== '') {
                    $found[] = trim((string) $node->nodeValue);
                }
            }

            return $found;
        };

        return new ProviderRecord(
            provider: $this->key(),
            externalId: $path,
            payload: [
                'title' => $value('a:title'),
                'authors' => $texts('a:author/a:name'),
                'summary' => $value('a:summary'),
                'subjects' => $texts('a:category/@term'),
                'rights' => $value('a:rights'),
                'epub' => $epub,
            ],
            sourceUrl: $id,
            fetchedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, fn ($item) => is_string($item) && trim($item) !== '')) : [];
    }
}
