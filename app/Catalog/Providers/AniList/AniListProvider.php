<?php

namespace App\Catalog\Providers\AniList;

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
use App\Enums\AgeRating;
use App\Enums\CreatorRole;
use App\Enums\PublicationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

class AniListProvider implements MetadataProvider
{
    use ClassifiesMediaType, SanitizesProviderData, SendsProviderRequests;

    private const ENDPOINT = 'https://graphql.anilist.co';

    private const PAGE_SIZE = 50;

    private const FIELDS = 'id idMal title { romaji english native } synonyms description(asHtml: false) format status countryOfOrigin isAdult startDate { year month day } chapters genres tags { name isMediaSpoiler } coverImage { extraLarge large } siteUrl updatedAt popularity favourites averageScore meanScore staff(perPage: 10, sort: RELEVANCE) { edges { role node { name { full } } } } characters(perPage: 5, sort: ROLE) { edges { node { name { full } } } }';

    /**
     * @var array<string, PublicationStatus>
     */
    private const STATUSES = [
        'FINISHED' => PublicationStatus::Completed,
        'RELEASING' => PublicationStatus::Ongoing,
        'NOT_YET_RELEASED' => PublicationStatus::Upcoming,
        'CANCELLED' => PublicationStatus::Cancelled,
        'HIATUS' => PublicationStatus::Hiatus,
    ];

    /**
     * AniList only tracks the original country, which is the best language signal it has.
     *
     * @var array<string, string>
     */
    private const LANGUAGES = ['JP' => 'ja', 'KR' => 'ko', 'CN' => 'zh', 'TW' => 'zh'];

    public function key(): string
    {
        return 'anilist';
    }

    /**
     * One GraphQL page; the token is the next page number. With a cursor, media newest-updated first until the cursor
     * is passed (AniList has no updatedAt filter), so the page that reaches it is the last. Without one, the whole
     * catalogue most popular first, so the first pages of a long sync are what readers want most.
     */
    public function page(?CarbonImmutable $since, ?string $pageToken): ProviderPage
    {
        $page = max(1, (int) $pageToken);
        $query = 'query ($page: Int, $sort: [MediaSort]) { Page(page: $page, perPage: '.self::PAGE_SIZE.') { pageInfo { hasNextPage } media(type: MANGA, sort: $sort) { '.self::FIELDS.' } } }';
        $data = $this->post($query, ['page' => $page, 'sort' => [$since ? 'UPDATED_AT_DESC' : 'POPULARITY_DESC']])->json('data.Page');
        $data = is_array($data) ? $data : [];
        $records = [];
        $newest = 0;
        $reachedCursor = false;

        foreach (is_array($data['media'] ?? null) ? $data['media'] : [] as $media) {
            $updatedAt = is_int($media['updatedAt'] ?? null) ? $media['updatedAt'] : 0;

            if ($since && $updatedAt < $since->getTimestamp()) {
                $reachedCursor = true;
                break;
            }

            $newest = max($newest, $updatedAt);
            $records[] = $this->record($media);
        }

        return new ProviderPage(
            records: $records,
            nextPageToken: ! $reachedCursor && ($data['pageInfo']['hasNextPage'] ?? false) ? (string) ($page + 1) : null,
            maxUpdatedAt: $newest > 0 ? CarbonImmutable::createFromTimestampUTC($newest) : null,
        );
    }

    public function find(string $externalId): ?ProviderRecord
    {
        $media = $this->post(
            'query ($id: Int) { Media(id: $id, type: MANGA) { '.self::FIELDS.' } }',
            ['id' => (int) $externalId],
        )->json('data.Media');

        return is_array($media) ? $this->record($media) : null;
    }

    public function normalize(ProviderRecord $record): NormalizedMedia
    {
        $payload = $record->payload;
        $titles = array_map($this->text(...), [$payload['title']['english'] ?? null, $payload['title']['romaji'] ?? null, $payload['title']['native'] ?? null]);
        $title = collect($titles)->first(fn (string $title) => $title !== '') ?? '';
        $country = $this->text($payload['countryOfOrigin'] ?? null);
        $start = is_array($payload['startDate'] ?? null) ? $payload['startDate'] : [];
        $isAdult = ($payload['isAdult'] ?? false) === true;
        $alternativeTitles = array_values(array_diff(array_unique(array_filter([...$titles, ...$this->strings($payload['synonyms'] ?? null)])), [$title]));
        $description = $this->plainText(preg_replace('~<br\s*/?>~i', "\n", $this->text($payload['description'] ?? null)));
        $genres = $this->strings($payload['genres'] ?? null);
        $tags = $this->tags($payload['tags'] ?? null);
        $chapters = is_int($payload['chapters'] ?? null) && $payload['chapters'] > 0 ? $payload['chapters'] : null;

        return new NormalizedMedia(
            title: $title,
            mediaTypeSlug: $this->classifyMediaType(new ClassificationSignals(
                provider: $this->key(),
                format: $this->text($payload['format'] ?? null),
                title: $title,
                alternativeTitles: $alternativeTitles,
                description: $description,
                subjects: [...$genres, ...$tags],
                chapterCount: $chapters,
                country: $country,
                language: self::LANGUAGES[$country] ?? null,
            ))->slug,
            alternativeTitles: $alternativeTitles,
            description: $description,
            creators: $this->creators($payload['staff']['edges'] ?? null),
            genres: $genres,
            tags: $tags,
            characters: $this->names($payload['characters']['edges'] ?? null),
            crossIds: is_int($payload['idMal'] ?? null) ? ['myanimelist' => (string) $payload['idMal']] : [],
            publicationYear: is_int($start['year'] ?? null) ? $start['year'] : null,
            publishedOn: $this->date($start),
            language: self::LANGUAGES[$country] ?? null,
            countryOfOrigin: strlen($country) === 2 ? $country : null,
            ageRating: $isAdult ? AgeRating::Adult : AgeRating::Unknown,
            status: self::STATUSES[$this->text($payload['status'] ?? null)] ?? PublicationStatus::Unknown,
            chapterCount: $chapters,
            coverUrl: $this->safeUrl($payload['coverImage']['extraLarge'] ?? $payload['coverImage']['large'] ?? null),
            sourceUrl: $this->safeUrl($record->sourceUrl),
            isAdult: $isAdult,
            metrics: $this->metrics($payload),
        );
    }

    /**
     * AniList scores run 0 to 100; the mean score stands in when the weighted average is missing.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, int|float>
     */
    private function metrics(array $payload): array
    {
        $metrics = [];

        foreach (['popularity', 'favourites'] as $name) {
            if (is_int($payload[$name] ?? null) && $payload[$name] > 0) {
                $metrics[$name] = $payload[$name];
            }
        }

        $score = collect([$payload['averageScore'] ?? null, $payload['meanScore'] ?? null])->first(fn ($value) => is_int($value) && $value > 0 && $value <= 100);

        if ($score !== null) {
            $metrics['average_rating'] = round($score / 20, 2);
        }

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function record(array $media): ProviderRecord
    {
        return new ProviderRecord(
            provider: $this->key(),
            externalId: (string) ($media['id'] ?? ''),
            payload: $media,
            sourceUrl: is_string($media['siteUrl'] ?? null) ? $media['siteUrl'] : null,
            fetchedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     *
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    private function post(string $query, array $variables): Response
    {
        return $this->send(fn () => $this->client()->post(self::ENDPOINT, ['query' => $query, 'variables' => $variables]));
    }

    /**
     * Story and original-creator roles write, art roles draw. Supervisors, editors and translators are not work-level creators.
     *
     * @return list<NormalizedCreator>
     */
    private function creators(mixed $edges): array
    {
        $creators = [];

        foreach (is_array($edges) ? $edges : [] as $edge) {
            $name = $this->text($edge['node']['name']['full'] ?? null);
            $role = mb_strtolower(preg_replace('/\(.*?\)/', '', $this->text($edge['role'] ?? null)));

            $roles = array_filter([
                preg_match('/\b(story|original)\b/', $role) ? CreatorRole::Author : null,
                preg_match('/\bart\b/', $role) ? CreatorRole::Artist : null,
                str_contains($role, 'illustration') ? CreatorRole::Illustrator : null,
            ]);

            foreach ($roles as $creatorRole) {
                if ($name !== '') {
                    $creators["{$name}|{$creatorRole->value}"] ??= new NormalizedCreator($name, $creatorRole);
                }
            }
        }

        return array_values($creators);
    }

    /**
     * @return list<string>
     */
    private function tags(mixed $tags): array
    {
        $names = [];

        foreach (is_array($tags) ? $tags : [] as $tag) {
            if (($tag['isMediaSpoiler'] ?? false) !== true && $this->text($tag['name'] ?? null) !== '') {
                $names[] = $this->text($tag['name']);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function names(mixed $edges): array
    {
        return array_values(array_filter(array_map(
            fn ($edge) => $this->text($edge['node']['name']['full'] ?? null),
            is_array($edges) ? $edges : [],
        )));
    }

    /**
     * @param  array<string, mixed>  $start
     */
    private function date(array $start): ?CarbonImmutable
    {
        [$year, $month, $day] = [$start['year'] ?? null, $start['month'] ?? null, $start['day'] ?? null];

        return is_int($year) && is_int($month) && is_int($day) && checkdate($month, $day, $year)
            ? CarbonImmutable::create($year, $month, $day)->startOfDay()
            : null;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        return array_values(array_filter(array_map($this->text(...), is_array($values) ? $values : [])));
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
