<?php

namespace App\Catalog\Providers\ComicVine;

use App\Catalog\Classification\ClassificationSignals;
use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderPage;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\Providers\ClassifiesMediaType;
use App\Catalog\Providers\MetadataProvider;
use App\Catalog\Providers\MissingProviderCredentials;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Catalog\Providers\SanitizesProviderData;
use App\Catalog\Providers\SendsProviderRequests;
use Carbon\CarbonImmutable;

class ComicVineProvider implements MetadataProvider
{
    use ClassifiesMediaType, SanitizesProviderData, SendsProviderRequests;

    private const API = 'https://comicvine.gamespot.com/api';

    private const PAGE_SIZE = 100;

    /**
     * Comic Vine stamps dates in US Pacific time and expects the filter in it too.
     */
    private const TIMEZONE = 'America/Los_Angeles';

    private const FIELDS = 'id,name,aliases,deck,description,image,publisher,start_year,count_of_issues,site_detail_url,date_last_updated';

    /** Fields only the single-volume endpoint returns: everyone credited on its issues, and its concepts (an imprint such as Ize Press). */
    private const CREDIT_FIELDS = 'people,concepts';

    /**
     * A blurb that marks a volume as an edition of a work from elsewhere ("French publication.", "English translation of the Korean manhwa").
     * Its credits are fetched so it can be recognised as that work; about one volume in seventy, so the hourly quota hardly notices.
     */
    private const FOREIGN_EDITION = '/\b(?:english|french|german|spanish|italian|portuguese|brazilian|polish|dutch|swedish|danish|norwegian|finnish|czech|hungarian|turkish|russian|indonesian|thai|vietnamese) (?:translation|publication|edition)\b|\btranslation of\b|\bproper (?:japanese|korean|chinese) title\b/i';

    public function key(): string
    {
        return 'comicvine';
    }

    /**
     * One page of 100 volumes (comic series runs); the token is the next offset. A full sync walks volumes with the most issues first,
     * the closest cheap stand-in for popularity Comic Vine offers (it exposes no follower or rating counts); with a cursor, volumes updated since it, newest first.
     * The API allows 200 requests an hour per key, which the source's rate limit keeps under.
     */
    public function page(?CarbonImmutable $since, ?string $pageToken): ProviderPage
    {
        $offset = max(0, (int) $pageToken);
        $query = [
            'field_list' => self::FIELDS,
            'limit' => self::PAGE_SIZE,
            'offset' => $offset,
            'sort' => $since ? 'date_last_updated:desc' : 'count_of_issues:desc',
        ];

        if ($since) {
            $query['filter'] = 'date_last_updated:'.$since->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:s').'|'.now(self::TIMEZONE)->format('Y-m-d H:i:s');
        }

        $body = $this->get('/volumes/', $query);
        $results = array_values(array_filter(is_array($body['results'] ?? null) ? $body['results'] : [], 'is_array'));
        $records = [];
        $newest = null;

        foreach ($results as $item) {
            $records[] = $this->record($this->isForeignEdition($item) ? $this->withCredits($item) : $item);
            $updated = $this->date($item['date_last_updated'] ?? null);
            $newest = $updated && (! $newest || $updated->greaterThan($newest)) ? $updated : $newest;
        }

        $next = $offset + self::PAGE_SIZE;
        $total = is_int($body['number_of_total_results'] ?? null) ? $body['number_of_total_results'] : 0;

        return new ProviderPage($records, $results !== [] && $next < $total ? (string) $next : null, $newest);
    }

    public function find(string $externalId): ?ProviderRecord
    {
        $body = $this->get('/volume/4050-'.rawurlencode($externalId).'/', ['field_list' => self::FIELDS.','.self::CREDIT_FIELDS]);
        $item = $body['results'] ?? null;

        return is_array($item) && $item !== [] && ! array_is_list($item) ? $this->record($item) : null;
    }

    public function normalize(ProviderRecord $record): NormalizedMedia
    {
        $item = $record->payload;
        $title = $this->text($item['name'] ?? null);
        $publisher = $this->text(is_array($item['publisher'] ?? null) ? ($item['publisher']['name'] ?? null) : null);
        $year = $this->text($item['start_year'] ?? null);
        $issues = $item['count_of_issues'] ?? null;
        $image = is_array($item['image'] ?? null) ? $item['image'] : [];
        $aliases = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', is_string($item['aliases'] ?? null) ? $item['aliases'] : '') ?: []), fn (string $alias) => $alias !== '' && $alias !== $title)));

        $deck = $this->plainText($item['deck'] ?? null);
        $description = $this->plainText($item['description'] ?? null) ?? $deck;
        $concepts = $this->names($item['concepts'] ?? null);

        // Comic Vine lists prose too (novelizations, "Batman: The Novel"): the deck and the name say which, so they are read together.
        $verdict = $this->classifyMediaType(new ClassificationSignals(
            provider: $this->key(),
            title: $title,
            alternativeTitles: $aliases,
            description: trim("{$deck} {$description}"),
            // A concept can be an imprint (Ize Press) or a format (Webtoon), so it is read as both.
            subjects: $concepts,
            publishers: [...($publisher !== '' ? [$publisher] : []), ...$concepts],
            chapterCount: is_int($issues) && $issues > 0 ? $issues : null,
            language: 'en',
        ));

        return new NormalizedMedia(
            title: $title,
            mediaTypeSlug: $verdict->slug,
            additionalMediaTypes: $verdict->additional,
            alternativeTitles: array_slice($aliases, 0, 25),
            description: $description,
            publishers: $publisher !== '' ? [$publisher] : [],
            publicationYear: preg_match('/^\d{4}$/', $year) ? (int) $year : null,
            language: 'en',
            chapterCount: is_int($issues) && $issues > 0 ? $issues : null,
            coverUrl: $this->safeUrl($image['medium_url'] ?? $image['original_url'] ?? null),
            sourceUrl: $this->safeUrl($record->sourceUrl),
            credits: $this->names($item['people'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isForeignEdition(array $item): bool
    {
        return preg_match(self::FOREIGN_EDITION, ($this->plainText($item['deck'] ?? null) ?? '').' '.($this->plainText($item['description'] ?? null) ?? '')) === 1;
    }

    /**
     * The volume with its credits and concepts from the single-volume endpoint; unchanged when that answers nothing.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function withCredits(array $item): array
    {
        $id = $item['id'] ?? null;
        $detail = is_int($id) ? ($this->get("/volume/4050-{$id}/", ['field_list' => self::CREDIT_FIELDS])['results'] ?? null) : null;

        return is_array($detail) && ! array_is_list($detail) ? [...$item, ...array_intersect_key($detail, ['people' => true, 'concepts' => true])] : $item;
    }

    /**
     * The names in a Comic Vine list of objects (`[{"name": "Chugong"}, ...]`).
     *
     * @return list<string>
     */
    private function names(mixed $objects): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $object): string => $this->text(is_array($object) ? ($object['name'] ?? null) : null),
            is_array($objects) ? $objects : [],
        ))));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function record(array $item): ProviderRecord
    {
        $id = $item['id'] ?? null;

        return new ProviderRecord(
            provider: $this->key(),
            externalId: is_int($id) || (is_string($id) && ctype_digit($id)) ? (string) $id : '',
            payload: $item,
            sourceUrl: $this->text($item['site_detail_url'] ?? null) ?: null,
            fetchedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws MissingProviderCredentials
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    private function get(string $path, array $query): array
    {
        $key = trim((string) config('bookflix.providers.comicvine.api_key'));

        if ($key === '') {
            throw MissingProviderCredentials::for('comicvine', 'BOOKFLIX_COMICVINE_API_KEY');
        }

        $response = $this->send(fn () => $this->client()->get(self::API.$path, ['api_key' => $key, 'format' => 'json', ...$query]));
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $status = $body['status_code'] ?? 1;

        // Comic Vine answers HTTP 200 with a status code: 100 bad key, 107 hourly limit hit.
        if ($status === 107) {
            throw new RateLimitedByProvider(900);
        }

        if ($response->status() === 404 || $status === 101) {
            return [];
        }

        if ($status !== 1) {
            throw new ProviderUnavailable('Comic Vine answered: '.($this->text($body['error'] ?? null) ?: "status {$status}").'.');
        }

        return $body;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, self::TIMEZONE) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : (is_int($value) ? (string) $value : '');
    }
}
