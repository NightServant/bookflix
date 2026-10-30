<?php

namespace App\Catalog\Providers\MangaUpdates;

use App\Catalog\Classification\ClassificationSignals;
use App\Catalog\Data\NormalizedCreator;
use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderPage;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\Providers\ClassifiesMediaType;
use App\Catalog\Providers\MetadataProvider;
use App\Catalog\Providers\SanitizesProviderData;
use App\Catalog\Providers\SendsProviderRequests;
use App\Enums\AgeRating;
use App\Enums\CreatorRole;
use App\Enums\PublicationStatus;
use Carbon\CarbonImmutable;

class MangaUpdatesProvider implements MetadataProvider
{
    use ClassifiesMediaType, SanitizesProviderData, SendsProviderRequests;

    private const API = 'https://api.mangaupdates.com/v1';

    private const PAGE_SIZE = 50;

    /**
     * Genres that make a series adult content, which the catalogue never stores.
     *
     * @var list<string>
     */
    private const ADULT_GENRES = ['Hentai', 'Adult'];

    /**
     * @var array<string, array{?string, ?string}> language and country of a series type; a novel says neither
     */
    private const ORIGINS = [
        'Manga' => ['ja', 'JP'],
        'Manhwa' => ['ko', 'KR'],
        'Manhua' => ['zh', 'CN'],
    ];

    public function key(): string
    {
        return 'mangaupdates';
    }

    /**
     * One search page of 50; the token is the next page number. MangaUpdates has no follows order, but ranking by the
     * number of readers' lists (`list_reading`) is the closest popularity-first order, so a full sync starts with what readers
     * want most. With a cursor, series newest first until one last updated before it (there is no "since" filter).
     * The API serves at most 10000 hits, so the run ends there.
     */
    public function page(?CarbonImmutable $since, ?string $pageToken): ProviderPage
    {
        $page = max(1, (int) $pageToken);
        $body = $this->send(fn () => $this->client()->post(self::API.'/series/search', [
            'perpage' => self::PAGE_SIZE,
            'page' => $page,
            'orderby' => $since ? 'date_added' : 'list_reading',
            'exclude_genre' => self::ADULT_GENRES,
        ]))->json();
        $results = is_array($body['results'] ?? null) ? $body['results'] : [];
        $records = [];
        $newest = 0;
        $reachedCursor = false;

        foreach ($results as $result) {
            $series = is_array($result['record'] ?? null) ? $result['record'] : null;

            if ($series === null) {
                continue;
            }

            $updatedAt = is_int($series['last_updated']['timestamp'] ?? null) ? $series['last_updated']['timestamp'] : 0;

            if ($since && $updatedAt < $since->getTimestamp()) {
                $reachedCursor = true;
                break;
            }

            $newest = max($newest, $updatedAt);
            $records[] = $this->record($series);
        }

        $total = is_int($body['total_hits'] ?? null) ? $body['total_hits'] : 0;

        return new ProviderPage(
            records: $records,
            nextPageToken: ! $reachedCursor && $results !== [] && $page * self::PAGE_SIZE < $total ? (string) ($page + 1) : null,
            maxUpdatedAt: $newest > 0 ? CarbonImmutable::createFromTimestampUTC($newest) : null,
        );
    }

    public function find(string $externalId): ?ProviderRecord
    {
        $series = $this->send(fn () => $this->client()->get(self::API.'/series/'.rawurlencode($externalId)))->json();

        return is_array($series) && isset($series['series_id']) ? $this->record($series) : null;
    }

    public function normalize(ProviderRecord $record): NormalizedMedia
    {
        $payload = $record->payload;
        $title = $this->text($payload['title'] ?? null);
        $genres = array_values(array_unique(array_filter(array_map(fn ($genre) => is_array($genre) ? $this->text($genre['genre'] ?? null) : '', is_array($payload['genres'] ?? null) ? $payload['genres'] : []))));
        $isAdult = array_intersect(self::ADULT_GENRES, $genres) !== [];
        $type = $this->text($payload['type'] ?? null);
        [$language, $country] = self::ORIGINS[$type] ?? [null, null];
        $year = $this->text($payload['year'] ?? null);
        $alternativeTitles = array_slice(array_values(array_diff(array_unique(array_filter(array_map(fn ($alt) => is_array($alt) ? $this->text($alt['title'] ?? null) : '', is_array($payload['associated'] ?? null) ? $payload['associated'] : []))), [$title])), 0, 25);
        $description = $this->plainText($payload['description'] ?? null);
        $categories = $this->categories($payload['categories'] ?? null);

        return new NormalizedMedia(
            title: $title,
            mediaTypeSlug: $this->classifyMediaType(new ClassificationSignals(
                provider: $this->key(),
                format: $type,
                title: $title,
                alternativeTitles: $alternativeTitles,
                description: $description,
                subjects: [...$genres, ...$categories],
                country: $country,
                language: $language,
            ))->slug,
            alternativeTitles: $alternativeTitles,
            description: $description,
            creators: $this->creators($payload['authors'] ?? null),
            genres: $genres,
            tags: $categories,
            publicationYear: preg_match('/^\d{4}$/', $year) ? (int) $year : null,
            language: $language,
            countryOfOrigin: $country,
            ageRating: $isAdult ? AgeRating::Adult : (in_array('Smut', $genres, true) ? AgeRating::Mature : AgeRating::Unknown),
            status: $this->status($payload),
            coverUrl: $this->safeUrl($payload['image']['url']['original'] ?? null),
            sourceUrl: $this->safeUrl($record->sourceUrl),
            isAdult: $isAdult,
            metrics: $this->metrics($payload),
        );
    }

    /**
     * Votes become the ratings count, the Bayesian rating (0 to 10) is halved to 0 to 5, and readers' lists count as follows.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, int|float>
     */
    private function metrics(array $payload): array
    {
        $metrics = [];
        $reading = $payload['rank']['lists']['reading'] ?? null;

        if (is_int($reading) && $reading > 0) {
            $metrics['follows'] = $reading;
        }

        if (is_int($payload['rating_votes'] ?? null) && $payload['rating_votes'] > 0) {
            $metrics['ratings_count'] = $payload['rating_votes'];
        }

        $rating = $payload['bayesian_rating'] ?? null;

        if (is_numeric($rating) && $rating > 0 && $rating <= 10) {
            $metrics['average_rating'] = round((float) $rating / 2, 2);
        }

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function status(array $payload): PublicationStatus
    {
        if (($payload['completed'] ?? null) === true) {
            return PublicationStatus::Completed;
        }

        $status = mb_strtolower($this->text($payload['status'] ?? null));

        return match (true) {
            str_contains($status, 'hiatus') => PublicationStatus::Hiatus,
            str_contains($status, 'cancel'), str_contains($status, 'discontinued') => PublicationStatus::Cancelled,
            str_contains($status, 'ongoing') => PublicationStatus::Ongoing,
            str_contains($status, 'complete') => PublicationStatus::Completed,
            default => PublicationStatus::Unknown,
        };
    }

    /**
     * @return list<NormalizedCreator>
     */
    private function creators(mixed $authors): array
    {
        $creators = [];

        foreach (is_array($authors) ? $authors : [] as $author) {
            $name = is_array($author) ? $this->text($author['name'] ?? null) : '';
            $role = is_array($author) ? ['Author' => CreatorRole::Author, 'Artist' => CreatorRole::Artist][$this->text($author['type'] ?? null)] ?? null : null;

            if ($name !== '' && $role) {
                $creators["{$name}|{$role->value}"] ??= new NormalizedCreator($name, $role);
            }
        }

        return array_values($creators);
    }

    /**
     * Only categories that readers voted up count as tags.
     *
     * @return list<string>
     */
    private function categories(mixed $categories): array
    {
        $names = [];

        foreach (is_array($categories) ? $categories : [] as $category) {
            $name = is_array($category) ? $this->text($category['category'] ?? null) : '';

            if ($name !== '' && (! is_int($category['votes'] ?? null) || $category['votes'] > 0)) {
                $names[] = $name;
            }
        }

        return array_slice(array_values(array_unique($names)), 0, 30);
    }

    /**
     * @param  array<string, mixed>  $series
     */
    private function record(array $series): ProviderRecord
    {
        return new ProviderRecord(
            provider: $this->key(),
            externalId: (string) ($series['series_id'] ?? ''),
            payload: $series,
            sourceUrl: is_string($series['url'] ?? null) ? $series['url'] : null,
            fetchedAt: CarbonImmutable::now(),
        );
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
