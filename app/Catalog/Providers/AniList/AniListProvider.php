<?php

namespace App\Catalog\Providers\AniList;

use App\Catalog\Data\NormalizedCreator;
use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\Providers\MetadataProvider;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Catalog\Providers\SendsProviderRequests;
use App\Enums\AgeRating;
use App\Enums\CreatorRole;
use App\Enums\PublicationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\LazyCollection;

class AniListProvider implements MetadataProvider
{
    use SendsProviderRequests;

    private const ENDPOINT = 'https://graphql.anilist.co';

    private const PAGE_SIZE = 50;

    private const FIELDS = 'id idMal title { romaji english native } synonyms description(asHtml: false) format status countryOfOrigin isAdult startDate { year month day } chapters genres tags { name isMediaSpoiler } coverImage { extraLarge large } siteUrl updatedAt staff(perPage: 10, sort: RELEVANCE) { edges { role node { name { full } } } } characters(perPage: 5, sort: ROLE) { edges { node { name { full } } } }';

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
     * With a cursor, media newest-updated first until the cursor is passed; AniList has no updatedAt filter.
     * Without one, the whole catalogue in id order.
     *
     * @return LazyCollection<int, ProviderRecord>
     */
    public function changedSince(?CarbonImmutable $since): LazyCollection
    {
        return LazyCollection::make(function () use ($since) {
            $query = 'query ($page: Int, $sort: [MediaSort]) { Page(page: $page, perPage: '.self::PAGE_SIZE.') { pageInfo { hasNextPage } media(type: MANGA, sort: $sort) { '.self::FIELDS.' } } }';
            $page = 1;

            do {
                $data = $this->post($query, ['page' => $page++, 'sort' => [$since ? 'UPDATED_AT_DESC' : 'ID']])->json('data.Page');
                $data = is_array($data) ? $data : [];

                foreach (is_array($data['media'] ?? null) ? $data['media'] : [] as $media) {
                    if ($since && ($media['updatedAt'] ?? 0) < $since->getTimestamp()) {
                        return;
                    }

                    yield $this->record($media);
                }
            } while ($data['pageInfo']['hasNextPage'] ?? false);
        });
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

        return new NormalizedMedia(
            title: $title,
            mediaTypeSlug: $this->mediaTypeSlug($this->text($payload['format'] ?? null), $country),
            alternativeTitles: array_values(array_diff(array_unique(array_filter([...$titles, ...$this->strings($payload['synonyms'] ?? null)])), [$title])),
            description: $this->description($payload['description'] ?? null),
            creators: $this->creators($payload['staff']['edges'] ?? null),
            genres: $this->strings($payload['genres'] ?? null),
            tags: $this->tags($payload['tags'] ?? null),
            characters: $this->names($payload['characters']['edges'] ?? null),
            crossIds: is_int($payload['idMal'] ?? null) ? ['myanimelist' => (string) $payload['idMal']] : [],
            publicationYear: is_int($start['year'] ?? null) ? $start['year'] : null,
            publishedOn: $this->date($start),
            language: self::LANGUAGES[$country] ?? null,
            countryOfOrigin: strlen($country) === 2 ? $country : null,
            ageRating: $isAdult ? AgeRating::Adult : AgeRating::Unknown,
            status: self::STATUSES[$this->text($payload['status'] ?? null)] ?? PublicationStatus::Unknown,
            chapterCount: is_int($payload['chapters'] ?? null) && $payload['chapters'] > 0 ? $payload['chapters'] : null,
            coverUrl: $this->text($payload['coverImage']['extraLarge'] ?? $payload['coverImage']['large'] ?? null) ?: null,
            sourceUrl: $record->sourceUrl,
            isAdult: $isAdult,
        );
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
     * NOVEL is a light novel whatever its country; a comic's country picks manga, manhwa or manhua.
     * Anything else yields an empty slug so the validator skips it.
     */
    private function mediaTypeSlug(string $format, string $country): string
    {
        if ($format === 'NOVEL') {
            return 'light-novel';
        }

        if (! in_array($format, ['MANGA', 'ONE_SHOT'], true)) {
            return '';
        }

        return match ($country) {
            'JP' => 'manga',
            'KR' => 'manhwa',
            'CN', 'TW' => 'manhua',
            default => '',
        };
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

    private function description(mixed $description): ?string
    {
        $text = is_string($description) ? trim(html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i', "\n", $description)))) : '';

        return $text === '' ? null : $text;
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
