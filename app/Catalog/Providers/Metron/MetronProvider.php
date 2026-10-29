<?php

namespace App\Catalog\Providers\Metron;

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
use App\Enums\PublicationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;

class MetronProvider implements MetadataProvider
{
    use ClassifiesMediaType, SanitizesProviderData, SendsProviderRequests;

    private const API = 'https://metron.cloud/api';

    /**
     * Metron's series list is thin (no description, genres, publisher or cross ids), so each series is also fetched in full.
     * A page job makes one list request plus at most this many detail requests; with the source limited to one page job a minute that is under 20 requests a minute.
     * A list page holds more series than this, so the token carries how many of them are done.
     */
    private const DETAILS_PER_PAGE = 18;

    /**
     * @var array<string, PublicationStatus>
     */
    private const STATUSES = [
        'ongoing' => PublicationStatus::Ongoing,
        'completed' => PublicationStatus::Completed,
        'cancelled' => PublicationStatus::Cancelled,
        'hiatus' => PublicationStatus::Hiatus,
    ];

    public function key(): string
    {
        return 'metron';
    }

    /**
     * The token is `{list page}:{series already done on it}`. Without a cursor the whole series list, with one only series modified since it.
     */
    public function page(?CarbonImmutable $since, ?string $pageToken): ProviderPage
    {
        [$listPage, $done] = array_map('intval', array_pad(explode(':', (string) $pageToken, 2), 2, '0')) + [1, 0];
        $listPage = max(1, $listPage);
        $done = max(0, $done);
        $query = ['page' => $listPage];

        if ($since) {
            $query['modified_gt'] = $since->utc()->format('Y-m-d\TH:i:s');
        }

        $body = $this->get('/series/', $query);
        $items = array_values(array_filter(is_array($body['results'] ?? null) ? $body['results'] : [], 'is_array'));
        $slice = array_slice($items, $done, self::DETAILS_PER_PAGE);
        $records = [];
        $newest = null;

        foreach ($slice as $item) {
            $id = $item['id'] ?? null;
            $detail = is_int($id) ? $this->get("/series/{$id}/", []) : [];
            $merged = [...$item, ...$detail];
            $records[] = $this->record($merged);
            $updated = $this->date($merged['modified'] ?? null);
            $newest = $updated && (! $newest || $updated->greaterThan($newest)) ? $updated : $newest;
        }

        $next = match (true) {
            $done + self::DETAILS_PER_PAGE < count($items) => "{$listPage}:".($done + self::DETAILS_PER_PAGE),
            $items !== [] && $this->text($body['next'] ?? null) !== '' => ($listPage + 1).':0',
            default => null,
        };

        return new ProviderPage($records, $next, $newest);
    }

    public function find(string $externalId): ?ProviderRecord
    {
        $detail = $this->get('/series/'.rawurlencode($externalId).'/', []);

        return $detail === [] ? null : $this->record($detail);
    }

    public function normalize(ProviderRecord $record): NormalizedMedia
    {
        $item = $record->payload;
        $type = $this->text(is_array($item['series_type'] ?? null) ? ($item['series_type']['name'] ?? null) : null);
        $publisher = $this->text(is_array($item['publisher'] ?? null) ? ($item['publisher']['name'] ?? null) : null);
        $imprint = $this->text(is_array($item['imprint'] ?? null) ? ($item['imprint']['name'] ?? null) : null);
        $issues = $item['issue_count'] ?? null;
        $year = $item['year_began'] ?? null;
        $status = $this->text($item['status'] ?? null);
        $genres = [];

        foreach (is_array($item['genres'] ?? null) ? $item['genres'] : [] as $genre) {
            $name = $this->text(is_array($genre) ? ($genre['name'] ?? null) : null);

            if ($name !== '') {
                $genres[] = $name;
            }
        }

        $crossIds = [];

        foreach (['comicvine' => 'cv_id', 'gcd' => 'gcd_id'] as $provider => $field) {
            $value = $this->text($item[$field] ?? null);

            if (ctype_digit($value) && (int) $value > 0) {
                $crossIds[$provider] = $value;
            }
        }

        $title = $this->text($item['name'] ?? null);
        $description = $this->plainText($item['desc'] ?? null);

        return new NormalizedMedia(
            title: $title,
            mediaTypeSlug: $this->classifyMediaType(new ClassificationSignals(
                provider: $this->key(),
                format: $type,
                title: $title,
                description: $description,
                subjects: array_values(array_unique($genres)),
                publishers: array_values(array_filter([$publisher, $imprint])),
                chapterCount: is_int($issues) && $issues > 0 ? $issues : null,
                language: 'en',
            )),
            description: $description,
            publishers: $publisher !== '' ? [$publisher] : [],
            genres: array_values(array_unique($genres)),
            crossIds: $crossIds,
            publicationYear: is_int($year) && $year > 0 ? $year : null,
            language: 'en',
            status: self::STATUSES[strtolower($status)] ?? PublicationStatus::Unknown,
            chapterCount: is_int($issues) && $issues > 0 ? $issues : null,
            sourceUrl: $this->safeUrl($record->sourceUrl),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function record(array $item): ProviderRecord
    {
        $id = $item['id'] ?? null;
        $id = is_int($id) ? (string) $id : '';

        return new ProviderRecord(
            provider: $this->key(),
            externalId: $id,
            payload: $item,
            sourceUrl: $id !== '' ? "https://metron.cloud/series/{$id}/" : null,
            fetchedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed> Empty for a 404.
     *
     * @throws MissingProviderCredentials
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    private function get(string $path, array $query): array
    {
        $username = trim((string) config('bookflix.providers.metron.username'));
        $password = (string) config('bookflix.providers.metron.password');

        if ($username === '' || $password === '') {
            throw MissingProviderCredentials::for('metron', 'BOOKFLIX_METRON_USERNAME and BOOKFLIX_METRON_PASSWORD');
        }

        /** @var PendingRequest $client */
        $client = $this->client()->withBasicAuth($username, $password);
        $response = $this->send(fn () => $client->get(self::API.$path, $query));
        $body = $response->json();

        return $response->status() === 404 || ! is_array($body) ? [] : $body;
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
        return is_string($value) ? trim($value) : (is_int($value) ? (string) $value : '');
    }
}
