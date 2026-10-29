<?php

namespace App\Catalog\Providers\OpenLibrary;

use App\Catalog\Classification\ClassificationSignals;
use App\Catalog\Data\NormalizedCreator;
use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderPage;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\Providers\ClassifiesMediaType;
use App\Catalog\Providers\MetadataProvider;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Catalog\Providers\SanitizesProviderData;
use App\Catalog\Providers\SendsProviderRequests;
use App\Enums\CreatorRole;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

class OpenLibraryProvider implements MetadataProvider
{
    use ClassifiesMediaType, SanitizesProviderData, SendsProviderRequests;

    private const BASE_URL = 'https://openlibrary.org';

    /**
     * Recent-changes entries per page job; a work costs its record, its editions and up to five authors in requests.
     */
    private const PAGE_SIZE = 10;

    private const KINDS = ['add-book', 'edit-book'];

    private const EDITION_LIMIT = 100;

    /**
     * A full sync walks these popularity lists instead of recent changes: the trending windows first, then the biggest subjects.
     */
    private const TRENDING_PERIODS = ['weekly', 'daily', 'monthly', 'yearly'];

    private const SUBJECTS = [
        'fantasy', 'science_fiction', 'romance', 'mystery_and_detective_stories', 'young_adult_fiction',
        'graphic_novels', 'horror', 'historical_fiction', 'thriller', 'classic_literature',
    ];

    /**
     * Pages read from each trending window or subject, at PAGE_SIZE works each.
     */
    private const DISCOVERY_PAGES = 5;

    private const MAX_AUTHORS = 5;

    /**
     * Open Library uses ISO 639-2 codes; Bookflix stores ISO 639-1.
     *
     * @var array<string, string>
     */
    private const LANGUAGES = [
        'eng' => 'en', 'jpn' => 'ja', 'kor' => 'ko', 'chi' => 'zh', 'fre' => 'fr',
        'ger' => 'de', 'spa' => 'es', 'ita' => 'it', 'por' => 'pt', 'rus' => 'ru',
    ];

    public function key(): string
    {
        return 'openlibrary';
    }

    /**
     * Works touched by book additions and edits since the given moment, newest first. The token is `kind:offset`
     * into the recent-changes feed (additions, then edits); a page is one slice of it, kept small because every work costs
     * several requests.
     * A null cursor (full sync) walks popularity instead: the trending windows, then popular subjects, with token `trending:weekly:0` /
     * `subject:fantasy:0` (page index within the list). A bulk import beyond that needs the Open Library dumps.
     * Edits to a single edition (/books/...) do not map back to their work yet.
     */
    public function page(?CarbonImmutable $since, ?string $pageToken): ProviderPage
    {
        if ($since === null) {
            return $this->discoveryPage($pageToken);
        }

        [$kind, $offset] = $this->position($pageToken);

        $changes = $this->asArray($this->get("/recentchanges/{$kind}.json", ['limit' => self::PAGE_SIZE, 'offset' => $offset])->json());
        $records = [];
        $seen = [];
        $newest = null;
        $reachedCursor = false;

        foreach ($changes as $change) {
            $timestamp = CarbonImmutable::parse($change['timestamp'] ?? 0, 'UTC');

            if ($timestamp->lt($since)) {
                $reachedCursor = true;
                break;
            }

            $newest = $newest === null || $timestamp->gt($newest) ? $timestamp : $newest;

            foreach ($this->asArray($change['changes'] ?? null) as $touched) {
                $id = $this->workId($touched['key'] ?? null);

                if ($id === null || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;

                if (($record = $this->find($id)) && $this->hasCoverOrDescription($record)) {
                    $records[] = $record;
                }
            }
        }

        $next = match (true) {
            ! $reachedCursor && count($changes) >= self::PAGE_SIZE => "{$kind}:".($offset + self::PAGE_SIZE),
            $kind === self::KINDS[0] => self::KINDS[1].':0',
            default => null,
        };

        return new ProviderPage($records, $next, $newest);
    }

    private function discoveryPage(?string $pageToken): ProviderPage
    {
        $lists = [
            ...array_map(fn (string $period): array => ['trending', $period], self::TRENDING_PERIODS),
            ...array_map(fn (string $subject): array => ['subject', $subject], self::SUBJECTS),
        ];
        [$kind, $name, $index] = array_pad(explode(':', (string) $pageToken, 3), 3, '');
        $position = array_search([$kind, $name], $lists, true);
        $position = $position === false ? 0 : $position;
        $index = max(0, (int) $index);
        [$kind, $name] = $lists[$position];

        $works = $kind === 'trending'
            ? $this->get("/trending/{$name}.json", ['limit' => self::PAGE_SIZE, 'page' => $index + 1])->json('works')
            : $this->get("/subjects/{$name}.json", ['limit' => self::PAGE_SIZE, 'offset' => $index * self::PAGE_SIZE])->json('works');
        $works = $this->asArray($works);

        $records = [];
        $seen = [];

        foreach ($works as $work) {
            $id = $this->workId($this->asArray($work)['key'] ?? null);

            if ($id === null || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            if ($record = $this->find($id)) {
                $records[] = new ProviderRecord($record->provider, $record->externalId, [...$record->payload, 'stats' => $this->stats($record->externalId)], $record->sourceUrl, $record->fetchedAt);
            }
        }

        $next = match (true) {
            count($works) >= self::PAGE_SIZE && $index + 1 < self::DISCOVERY_PAGES => "{$kind}:{$name}:".($index + 1),
            isset($lists[$position + 1]) => implode(':', $lists[$position + 1]).':0',
            default => null,
        };

        return new ProviderPage($records, $next, null);
    }

    /**
     * Reader counts of a work under the names Open Library's search index uses.
     *
     * @return array<string, int|float>
     */
    private function stats(string $id): array
    {
        $summary = $this->asArray($this->asArray($this->get("/works/{$id}/ratings.json")->json())['summary'] ?? null);
        $counts = $this->asArray($this->asArray($this->get("/works/{$id}/bookshelves.json")->json())['counts'] ?? null);
        $count = fn (string $key): int => is_int($counts[$key] ?? null) ? max(0, $counts[$key]) : 0;
        $stats = [];

        if (is_int($summary['count'] ?? null) && $summary['count'] > 0 && is_numeric($summary['average'] ?? null)) {
            $stats['ratings_count'] = $summary['count'];
            $stats['ratings_average'] = round((float) $summary['average'], 2);
        }

        if ($count('want_to_read') > 0) {
            $stats['want_to_read_count'] = $count('want_to_read');
        }

        $log = $count('want_to_read') + $count('currently_reading') + $count('already_read');

        return $log > 0 ? [...$stats, 'readinglog_count' => $log] : $stats;
    }

    /**
     * @param  array<mixed>  $stats
     * @return array<string, int|float>
     */
    private function metrics(array $stats): array
    {
        $metrics = [];

        foreach (['want_to_read_count' => 'follows', 'readinglog_count' => 'popularity', 'ratings_count' => 'ratings_count'] as $from => $to) {
            if (is_int($stats[$from] ?? null) && $stats[$from] > 0) {
                $metrics[$to] = $stats[$from];
            }
        }

        $average = $stats['ratings_average'] ?? null;

        if (is_numeric($average) && $average > 0 && $average <= 5) {
            $metrics['average_rating'] = round((float) $average, 2);
        }

        return $metrics;
    }

    /**
     * The feed is mostly brand-new, sparse works: one with neither a cover nor a description is not worth showing, so the sync skips it.
     */
    private function hasCoverOrDescription(ProviderRecord $record): bool
    {
        $media = $this->normalize($record);

        return $media->coverUrl !== null || filled($media->description);
    }

    public function find(string $externalId): ?ProviderRecord
    {
        $id = $externalId;
        $work = $this->get("/works/{$id}.json");

        if ($work->status() === 404) {
            return null;
        }

        $data = $this->asArray($work->json());

        if (($data['type']['key'] ?? null) === '/type/redirect') {
            $id = $this->workId($data['location'] ?? null) ?? $id;
            $work = $this->get("/works/{$id}.json");
            $data = $this->asArray($work->json());

            if ($work->status() === 404) {
                return null;
            }
        }

        $editions = $this->asArray($this->get("/works/{$id}/editions.json", ['limit' => self::EDITION_LIMIT])->json('entries'));

        return new ProviderRecord(
            provider: $this->key(),
            externalId: $id,
            payload: ['work' => $data, 'editions' => $editions, 'authors' => $this->authorNames($data)],
            sourceUrl: self::BASE_URL."/works/{$id}",
            fetchedAt: CarbonImmutable::now(),
        );
    }

    public function normalize(ProviderRecord $record): NormalizedMedia
    {
        $work = $this->asArray($record->payload['work'] ?? null);
        $editions = array_values(array_filter($this->asArray($record->payload['editions'] ?? null), 'is_array'));
        $subjects = array_values(array_unique(array_filter($this->asArray($work['subjects'] ?? null), 'is_string')));

        $primary = collect($editions)->sortBy(fn (array $edition) => $this->year($edition['publish_date'] ?? null) ?? PHP_INT_MAX)->first() ?? [];
        $years = array_filter([$this->year($work['first_publish_date'] ?? null), $this->year($primary['publish_date'] ?? null)]);
        $title = $this->text($work['title'] ?? null);
        $description = $this->plainText(is_array($work['description'] ?? null) ? ($work['description']['value'] ?? null) : ($work['description'] ?? null));
        $language = self::LANGUAGES[basename($this->text($primary['languages'][0]['key'] ?? null))] ?? null;
        $pages = is_int($primary['number_of_pages'] ?? null) && $primary['number_of_pages'] > 0 ? $primary['number_of_pages'] : null;

        return new NormalizedMedia(
            title: $title,
            mediaTypeSlug: $this->classifyMediaType(new ClassificationSignals(
                provider: $this->key(),
                title: $title,
                description: $description,
                subjects: $subjects,
                publishers: $this->publishers($editions),
                pageCount: $pages,
                language: $language,
                deweyCode: $this->deweyCode($editions),
            )),
            description: $description,
            creators: array_map(
                fn (string $name) => new NormalizedCreator($name, CreatorRole::Author),
                array_values(array_filter($this->asArray($record->payload['authors'] ?? null), 'is_string')),
            ),
            publishers: array_values(array_filter($this->asArray($primary['publishers'] ?? null), 'is_string')),
            genres: $subjects,
            isbns: $this->isbns($editions),
            publicationYear: $years === [] ? null : min($years),
            language: $language,
            pageCount: $pages,
            coverUrl: $this->safeUrl($this->coverUrl($work['covers'] ?? null)),
            externalDeweyCode: $this->deweyCode($editions),
            sourceUrl: $this->safeUrl($record->sourceUrl),
            metrics: $this->metrics($this->asArray($record->payload['stats'] ?? null)),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    private function get(string $path, array $query = []): Response
    {
        return $this->send(fn () => $this->client()->get(self::BASE_URL.$path, $query));
    }

    /**
     * @param  array<string, mixed>  $work
     * @return list<string>
     */
    private function authorNames(array $work): array
    {
        $names = [];

        foreach (array_slice($this->asArray($work['authors'] ?? null), 0, self::MAX_AUTHORS) as $role) {
            $key = $role['author']['key'] ?? null;

            if (! is_string($key) || ! preg_match('#^/authors/(OL\d+A)$#', $key, $match)) {
                continue;
            }

            $name = $this->get("/authors/{$match[1]}.json")->json('name');

            if (is_string($name) && trim($name) !== '') {
                $names[] = trim($name);
            }
        }

        return $names;
    }

    /**
     * The publishers of every edition (up to ten, the first edition's first): the work is a comic if any edition is from a comic publisher, a novel if the prose house is the one on record.
     *
     * @param  list<array<string, mixed>>  $editions
     * @return list<string>
     */
    private function publishers(array $editions): array
    {
        $names = [];

        foreach ($editions as $edition) {
            foreach ($this->asArray($edition['publishers'] ?? null) as $name) {
                if (is_string($name) && trim($name) !== '') {
                    $names[] = trim($name);
                }
            }
        }

        return array_slice(array_values(array_unique($names)), 0, 10);
    }

    /**
     * @param  list<array<string, mixed>>  $editions
     * @return list<string>
     */
    private function isbns(array $editions): array
    {
        $isbns = [];

        foreach ($editions as $edition) {
            foreach ($this->asArray($edition['isbn_13'] ?? null) as $isbn) {
                $isbn = is_string($isbn) ? str_replace('-', '', $isbn) : '';

                if (preg_match('/^\d{13}$/', $isbn)) {
                    $isbns[$isbn] = $isbn;
                }
            }
        }

        return array_values($isbns);
    }

    /**
     * The first Dewey class an edition carries, as `813.54` (Open Library stores `813/.54`). Prime marks can sit anywhere in the number
     * (`741.5/952`), and the digits after one are the place, so they are all dropped rather than cutting the class short.
     *
     * @param  list<array<string, mixed>>  $editions
     */
    private function deweyCode(array $editions): ?string
    {
        foreach ($editions as $edition) {
            foreach ($this->asArray($edition['dewey_decimal_class'] ?? null) as $class) {
                if (is_string($class) && preg_match('#^(\d{3})(?:\.(\d+))?#', str_replace(['/', "'"], '', trim($class)), $match)) {
                    return $match[1].(isset($match[2]) ? ".{$match[2]}" : '');
                }
            }
        }

        return null;
    }

    private function coverUrl(mixed $covers): ?string
    {
        foreach ($this->asArray($covers) as $id) {
            if (is_int($id) && $id > 0) {
                return "https://covers.openlibrary.org/b/id/{$id}-L.jpg";
            }
        }

        return null;
    }

    private function year(mixed $date): ?int
    {
        return is_string($date) && preg_match('/\b(1\d{3}|20\d{2})\b/', $date, $match) ? (int) $match[1] : null;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function position(?string $pageToken): array
    {
        [$kind, $offset] = array_pad(explode(':', (string) $pageToken, 2), 2, '0');

        return in_array($kind, self::KINDS, true) ? [$kind, max(0, (int) $offset)] : [self::KINDS[0], 0];
    }

    private function workId(mixed $key): ?string
    {
        return is_string($key) && preg_match('#^/works/(OL\d+W)$#', $key, $match) ? $match[1] : null;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return array<mixed>
     */
    private function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
