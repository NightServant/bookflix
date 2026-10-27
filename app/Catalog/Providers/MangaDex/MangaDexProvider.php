<?php

namespace App\Catalog\Providers\MangaDex;

use App\Catalog\Data\NormalizedCreator;
use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderPage;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\Providers\MetadataProvider;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Catalog\Providers\SanitizesProviderData;
use App\Catalog\Providers\SendsProviderRequests;
use App\Enums\AgeRating;
use App\Enums\CreatorRole;
use App\Enums\PublicationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

class MangaDexProvider implements MetadataProvider
{
    use SanitizesProviderData, SendsProviderRequests;

    private const API = 'https://api.mangadex.org';

    private const PAGE_SIZE = 100;

    /**
     * MangaDex rejects any request whose offset plus limit passes this.
     */
    private const MAX_WINDOW = 10000;

    /**
     * @var array<string, PublicationStatus>
     */
    private const STATUSES = [
        'ongoing' => PublicationStatus::Ongoing,
        'completed' => PublicationStatus::Completed,
        'hiatus' => PublicationStatus::Hiatus,
        'cancelled' => PublicationStatus::Cancelled,
    ];

    /**
     * @var array<string, string>
     */
    private const TYPES = ['ja' => 'manga', 'ko' => 'manhwa', 'zh' => 'manhua', 'zh-hk' => 'manhua'];

    /**
     * @var array<string, string>
     */
    private const COUNTRIES = ['ja' => 'JP', 'ko' => 'KR', 'zh' => 'CN', 'zh-hk' => 'HK'];

    public function key(): string
    {
        return 'mangadex';
    }

    /**
     * One page of 100 titles; the token is the next offset. Without a cursor the catalogue most followed first, so the
     * first pages of a long sync are what readers want most; with one, titles updated since it, newest first.
     * One statistics call per page supplies follows and ratings.
     */
    public function page(?CarbonImmutable $since, ?string $pageToken): ProviderPage
    {
        $offset = max(0, (int) $pageToken);
        $query = [
            'limit' => self::PAGE_SIZE,
            'offset' => $offset,
            'includes' => ['author', 'artist', 'cover_art'],
            'contentRating' => ['safe', 'suggestive'],
            $since ? 'order[updatedAt]' : 'order[followedCount]' => 'desc',
        ];

        if ($since) {
            $query['updatedAtSince'] = $since->utc()->format('Y-m-d\TH:i:s');
        }

        $body = $this->get('/manga', $query)->json();
        $items = array_values(array_filter(is_array($body['data'] ?? null) ? $body['data'] : [], 'is_array'));
        $statistics = $this->statistics(array_values(array_filter(array_map(fn (array $item) => $this->text($item['id'] ?? null), $items))));
        $records = [];
        $newest = null;

        foreach ($items as $item) {
            $item['statistics'] = $statistics[$this->text($item['id'] ?? null)] ?? [];
            $records[] = $this->record($item);
            $updated = $this->date($item['attributes']['updatedAt'] ?? null);
            $newest = $updated && (! $newest || $updated->greaterThan($newest)) ? $updated : $newest;
        }

        $next = $offset + self::PAGE_SIZE;
        $total = is_int($body['total'] ?? null) ? $body['total'] : 0;

        return new ProviderPage(
            records: $records,
            nextPageToken: $items !== [] && $next < $total && $next + self::PAGE_SIZE <= self::MAX_WINDOW ? (string) $next : null,
            maxUpdatedAt: $newest,
        );
    }

    public function find(string $externalId): ?ProviderRecord
    {
        $body = $this->get('/manga/'.rawurlencode($externalId), ['includes' => ['author', 'artist', 'cover_art']])->json();
        $item = is_array($body['data'] ?? null) ? $body['data'] : null;

        if ($item === null) {
            return null;
        }

        $item['statistics'] = $this->statistics([$externalId])[$externalId] ?? [];

        return $this->record($item);
    }

    public function normalize(ProviderRecord $record): NormalizedMedia
    {
        $payload = $record->payload;
        $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
        $language = $this->text($attributes['originalLanguage'] ?? null);
        $rating = $this->text($attributes['contentRating'] ?? null);
        $isAdult = in_array($rating, ['erotica', 'pornographic'], true);
        $titles = $this->titles($attributes);
        $title = $titles[0] ?? '';
        $links = is_array($attributes['links'] ?? null) ? $attributes['links'] : [];
        $id = $this->text($payload['id'] ?? null);
        $cover = $this->coverUrl($id, $payload['relationships'] ?? null);
        $chapters = $this->text($attributes['lastChapter'] ?? null);
        [$genres, $themes, $tags] = $this->tags($attributes['tags'] ?? null);

        return new NormalizedMedia(
            title: $title,
            mediaTypeSlug: self::TYPES[$language] ?? '',
            alternativeTitles: array_slice(array_values(array_diff($titles, [$title])), 0, 25),
            description: $this->plainText($this->localized($attributes['description'] ?? null)),
            creators: $this->creators($payload['relationships'] ?? null),
            genres: $genres,
            themes: $themes,
            tags: $tags,
            crossIds: $this->crossIds($links),
            publicationYear: is_int($attributes['year'] ?? null) ? $attributes['year'] : null,
            language: $language !== '' ? $language : null,
            countryOfOrigin: self::COUNTRIES[$language] ?? null,
            ageRating: match (true) {
                $isAdult => AgeRating::Adult,
                $rating === 'suggestive' => AgeRating::Teen,
                $rating === 'safe' => AgeRating::All,
                default => AgeRating::Unknown,
            },
            status: self::STATUSES[$this->text($attributes['status'] ?? null)] ?? PublicationStatus::Unknown,
            chapterCount: ctype_digit($chapters) && (int) $chapters > 0 ? (int) $chapters : null,
            coverUrl: $this->safeUrl($cover),
            sourceUrl: $this->safeUrl($record->sourceUrl),
            isAdult: $isAdult,
            metrics: $this->metrics($payload['statistics'] ?? null),
        );
    }

    /**
     * Follows, and the Bayesian (else plain) rating on MangaDex's 0 to 10 scale halved to 0 to 5.
     *
     * @return array<string, int|float>
     */
    private function metrics(mixed $statistics): array
    {
        $statistics = is_array($statistics) ? $statistics : [];
        $metrics = [];

        if (is_int($statistics['follows'] ?? null) && $statistics['follows'] > 0) {
            $metrics['follows'] = $statistics['follows'];
        }

        $rating = $statistics['rating'] ?? null;
        $score = is_array($rating) ? collect([$rating['bayesian'] ?? null, $rating['average'] ?? null])->first(fn ($value) => is_numeric($value) && $value > 0 && $value <= 10) : null;

        if ($score !== null) {
            $metrics['average_rating'] = round((float) $score / 2, 2);
        }

        return $metrics;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function statistics(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $statistics = $this->get('/statistics/manga', ['manga' => $ids])->json('statistics');

        return is_array($statistics) ? array_filter($statistics, 'is_array') : [];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function record(array $item): ProviderRecord
    {
        $id = $this->text($item['id'] ?? null);

        return new ProviderRecord(
            provider: $this->key(),
            externalId: $id,
            payload: $item,
            sourceUrl: $id !== '' ? "https://mangadex.org/title/{$id}" : null,
            fetchedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param  array<string, mixed>  $query  Lists are sent as `key[]=value`, which is what MangaDex expects.
     *
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    private function get(string $path, array $query): Response
    {
        $queryString = preg_replace('/%5B\d+%5D/', '%5B%5D', http_build_query($query));

        return $this->send(fn () => $this->client()->get(self::API.$path.'?'.$queryString));
    }

    /**
     * English first, then romanised Japanese, then whatever the title map holds; alternative titles follow in that order.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function titles(array $attributes): array
    {
        $maps = [is_array($attributes['title'] ?? null) ? $attributes['title'] : []];

        foreach (is_array($attributes['altTitles'] ?? null) ? $attributes['altTitles'] : [] as $alt) {
            if (is_array($alt)) {
                $maps[] = $alt;
            }
        }

        $titles = [];

        foreach (['en', 'ja-ro', null] as $language) {
            foreach ($maps as $map) {
                foreach ($language === null ? $map : array_intersect_key($map, [$language => true]) as $value) {
                    $titles[] = $this->text($value);
                }
            }
        }

        return array_values(array_unique(array_filter($titles)));
    }

    private function localized(mixed $map): string
    {
        $map = is_array($map) ? $map : [];

        return $this->text($map['en'] ?? null);
    }

    /**
     * MangaDex tag groups: genre and theme tags go to the taxonomy mapper as genres and themes, format and content tags stay tags.
     *
     * @return array{list<string>, list<string>, list<string>}
     */
    private function tags(mixed $tags): array
    {
        $grouped = ['genre' => [], 'theme' => [], 'other' => []];

        foreach (is_array($tags) ? $tags : [] as $tag) {
            $name = $this->text($tag['attributes']['name']['en'] ?? null);
            $group = $this->text($tag['attributes']['group'] ?? null);

            if ($name !== '') {
                $grouped[isset($grouped[$group]) ? $group : 'other'][] = $name;
            }
        }

        return [array_values(array_unique($grouped['genre'])), array_values(array_unique($grouped['theme'])), array_values(array_unique($grouped['other']))];
    }

    /**
     * @param  array<string, mixed>  $links
     * @return array<string, string>
     */
    private function crossIds(array $links): array
    {
        $ids = [];
        $anilist = $this->text($links['al'] ?? null);
        $mal = $this->text($links['mal'] ?? null);
        $updates = strtolower($this->text($links['mu'] ?? null));

        if (ctype_digit($anilist)) {
            $ids['anilist'] = $anilist;
        }

        if (ctype_digit($mal)) {
            $ids['myanimelist'] = $mal;
        }

        // MangaUpdates ids are now base 36 slugs in links; its API ids are the same number in decimal.
        if ($updates !== '' && ctype_alnum($updates)) {
            $ids['mangaupdates'] = ctype_digit($updates) ? $updates : (string) intval($updates, 36);
        }

        return $ids;
    }

    private function coverUrl(string $id, mixed $relationships): ?string
    {
        foreach (is_array($relationships) ? $relationships : [] as $relationship) {
            $file = $this->text($relationship['attributes']['fileName'] ?? null);

            if (($relationship['type'] ?? null) === 'cover_art' && $file !== '' && $id !== '') {
                return "https://uploads.mangadex.org/covers/{$id}/{$file}.512.jpg";
            }
        }

        return null;
    }

    /**
     * @return list<NormalizedCreator>
     */
    private function creators(mixed $relationships): array
    {
        $creators = [];

        foreach (is_array($relationships) ? $relationships : [] as $relationship) {
            $role = match ($relationship['type'] ?? null) {
                'author' => CreatorRole::Author,
                'artist' => CreatorRole::Artist,
                default => null,
            };
            $name = $this->text($relationship['attributes']['name'] ?? null);

            if ($role && $name !== '') {
                $creators["{$name}|{$role->value}"] ??= new NormalizedCreator($name, $role);
            }
        }

        return array_values($creators);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
