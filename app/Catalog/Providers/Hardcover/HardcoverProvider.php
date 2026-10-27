<?php

namespace App\Catalog\Providers\Hardcover;

use App\Catalog\Data\NormalizedCreator;
use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderPage;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\Providers\MetadataProvider;
use App\Catalog\Providers\MissingProviderCredentials;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\RateLimitedByProvider;
use App\Catalog\Providers\SanitizesProviderData;
use App\Catalog\Providers\SendsProviderRequests;
use App\Enums\CreatorRole;
use Carbon\CarbonImmutable;

class HardcoverProvider implements MetadataProvider
{
    use SanitizesProviderData, SendsProviderRequests;

    private const API = 'https://api.hardcover.app/v1/graphql';

    private const PAGE_SIZE = 50;

    private const FIELDS = <<<'GRAPHQL'
        id slug title subtitle description release_year release_date pages users_count users_read_count rating ratings_count updated_at
        image { url }
        contributions { contribution author { name } }
        cached_tags
        book_series { position series { name } }
        editions(limit: 1, where: {isbn_13: {_is_null: false}}) { isbn_13 }
        GRAPHQL;

    /**
     * @var array<string, CreatorRole>
     */
    private const ROLES = [
        'author' => CreatorRole::Author,
        'writer' => CreatorRole::Writer,
        'illustrator' => CreatorRole::Illustrator,
        'translator' => CreatorRole::Translator,
        'penciller' => CreatorRole::Penciller,
        'inker' => CreatorRole::Inker,
        'colorist' => CreatorRole::Colorist,
    ];

    public function key(): string
    {
        return 'hardcover';
    }

    /**
     * One page of 50 books; the token is the next offset. Without a cursor books with the most readers first, so a long sync gets the popular ones early;
     * with one, books updated since it, newest first. Hardcover gives no total, so a short page is the last.
     */
    public function page(?CarbonImmutable $since, ?string $pageToken): ProviderPage
    {
        $offset = max(0, (int) $pageToken);
        $fields = self::FIELDS;
        $query = $since
            ? "query (\$limit: Int!, \$offset: Int!, \$since: timestamptz!) { books(limit: \$limit, offset: \$offset, where: {updated_at: {_gt: \$since}}, order_by: {updated_at: desc}) { {$fields} } }"
            : "query (\$limit: Int!, \$offset: Int!) { books(limit: \$limit, offset: \$offset, order_by: {users_count: desc}) { {$fields} } }";
        $variables = ['limit' => self::PAGE_SIZE, 'offset' => $offset] + ($since ? ['since' => $since->utc()->format('Y-m-d\TH:i:s\Z')] : []);
        $books = $this->books($this->post($query, $variables));
        $records = [];
        $newest = null;

        foreach ($books as $book) {
            $records[] = $this->record($book);
            $updated = $this->date($book['updated_at'] ?? null);
            $newest = $updated && (! $newest || $updated->greaterThan($newest)) ? $updated : $newest;
        }

        return new ProviderPage($records, count($books) >= self::PAGE_SIZE ? (string) ($offset + self::PAGE_SIZE) : null, $newest);
    }

    public function find(string $externalId): ?ProviderRecord
    {
        $fields = self::FIELDS;
        $books = $this->books($this->post("query (\$id: Int!) { books(where: {id: {_eq: \$id}}, limit: 1) { {$fields} } }", ['id' => (int) $externalId]));

        return $books === [] ? null : $this->record($books[0]);
    }

    public function normalize(ProviderRecord $record): NormalizedMedia
    {
        $book = $record->payload;
        $tags = $this->tags($book['cached_tags'] ?? null);
        $title = $this->text($book['title'] ?? null);
        $subtitle = $this->text($book['subtitle'] ?? null);
        $image = is_array($book['image'] ?? null) ? $book['image'] : [];
        $year = $book['release_year'] ?? null;
        $pages = $book['pages'] ?? null;
        $genreSlugs = array_map('strtolower', $tags['Genre']);

        return new NormalizedMedia(
            title: $title,
            mediaTypeSlug: match (true) {
                (bool) array_intersect($genreSlugs, ['graphic novels', 'graphic novel', 'manga']) => 'graphic-novel',
                (bool) array_intersect($genreSlugs, ['comics', 'comic books']) => 'comic-book',
                default => 'novel',
            },
            alternativeTitles: $subtitle !== '' && $title !== '' ? ["{$title}: {$subtitle}"] : [],
            description: $this->plainText($book['description'] ?? null),
            creators: $this->creators($book['contributions'] ?? null),
            series: $this->series($book['book_series'] ?? null),
            genres: $tags['Genre'],
            themes: [],
            tags: array_values(array_unique([...$tags['Mood'], ...$tags['Tag']])),
            isbns: $this->isbns($book['editions'] ?? null),
            publicationYear: is_int($year) && $year > 0 ? $year : null,
            publishedOn: $this->date($book['release_date'] ?? null),
            contentWarnings: $tags['Content Warning'],
            pageCount: is_int($pages) && $pages > 0 ? $pages : null,
            coverUrl: $this->safeUrl($image['url'] ?? null),
            sourceUrl: $this->safeUrl($record->sourceUrl),
            isAdult: in_array('erotica', $genreSlugs, true),
            metrics: $this->metrics($book),
        );
    }

    /**
     * @param  array<string, mixed>  $book
     * @return array<string, int|float>
     */
    private function metrics(array $book): array
    {
        $metrics = [];

        foreach (['popularity' => 'users_count', 'follows' => 'users_read_count', 'ratings_count' => 'ratings_count'] as $metric => $field) {
            if (is_int($book[$field] ?? null) && $book[$field] > 0) {
                $metrics[$metric] = $book[$field];
            }
        }

        $rating = $book['rating'] ?? null;

        if (is_numeric($rating) && $rating > 0 && $rating <= 5) {
            $metrics['average_rating'] = round((float) $rating, 2);
        }

        return $metrics;
    }

    /**
     * `cached_tags` maps a category to its tags: {"Genre": [{"tag": "Fantasy", "count": 12}], "Mood": [...], "Content Warning": [...], "Tag": [...]}.
     * Each category keeps its ten most applied tags.
     *
     * @return array{Genre: list<string>, Mood: list<string>, 'Content Warning': list<string>, Tag: list<string>}
     */
    private function tags(mixed $cached): array
    {
        $cached = is_array($cached) ? $cached : [];
        $out = ['Genre' => [], 'Mood' => [], 'Content Warning' => [], 'Tag' => []];

        foreach (array_keys($out) as $category) {
            $entries = array_filter(is_array($cached[$category] ?? null) ? $cached[$category] : [], 'is_array');
            usort($entries, fn (array $a, array $b) => (is_numeric($b['count'] ?? null) ? $b['count'] : 0) <=> (is_numeric($a['count'] ?? null) ? $a['count'] : 0));

            foreach (array_slice($entries, 0, 10) as $entry) {
                $name = $this->text($entry['tag'] ?? null);

                if ($name !== '') {
                    $out[$category][] = $name;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<NormalizedCreator>
     */
    private function creators(mixed $contributions): array
    {
        $creators = [];

        foreach (is_array($contributions) ? $contributions : [] as $contribution) {
            $name = $this->text(is_array($contribution['author'] ?? null) ? ($contribution['author']['name'] ?? null) : null);
            $role = self::ROLES[strtolower($this->text($contribution['contribution'] ?? null) ?: 'author')] ?? null;

            if ($name !== '' && $role) {
                $creators["{$name}|{$role->value}"] ??= new NormalizedCreator($name, $role);
            }
        }

        return array_values($creators);
    }

    /**
     * @return array{title: string, position: ?string}|null
     */
    private function series(mixed $bookSeries): ?array
    {
        foreach (is_array($bookSeries) ? $bookSeries : [] as $entry) {
            $title = $this->text(is_array($entry['series'] ?? null) ? ($entry['series']['name'] ?? null) : null);
            $position = $entry['position'] ?? null;

            if ($title !== '') {
                return ['title' => $title, 'position' => is_numeric($position) ? (string) (float) $position : null];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function isbns(mixed $editions): array
    {
        $isbns = [];

        foreach (is_array($editions) ? $editions : [] as $edition) {
            $isbn = preg_replace('/[\s-]/', '', $this->text(is_array($edition) ? ($edition['isbn_13'] ?? null) : null));

            if (is_string($isbn) && preg_match('/^97[89]\d{10}$/', $isbn)) {
                $isbns[] = $isbn;
            }
        }

        return array_values(array_unique($isbns));
    }

    /**
     * @param  array<string, mixed>  $book
     */
    private function record(array $book): ProviderRecord
    {
        $id = $book['id'] ?? null;
        $slug = $this->text($book['slug'] ?? null);

        return new ProviderRecord(
            provider: $this->key(),
            externalId: is_int($id) ? (string) $id : '',
            payload: $book,
            sourceUrl: $slug !== '' && preg_match('/^[\w-]+$/', $slug) ? "https://hardcover.app/books/{$slug}" : null,
            fetchedAt: CarbonImmutable::now(),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function books(array $body): array
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        return array_values(array_filter(is_array($data['books'] ?? null) ? $data['books'] : [], 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws MissingProviderCredentials
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    private function post(string $query, array $variables): array
    {
        $token = trim((string) preg_replace('/^\s*Bearer\s+/i', '', (string) config('bookflix.providers.hardcover.api_token')));

        if ($token === '') {
            throw MissingProviderCredentials::for('hardcover', 'BOOKFLIX_HARDCOVER_API_TOKEN');
        }

        $response = $this->send(fn () => $this->client()->withHeaders(['authorization' => "Bearer {$token}"])->post(self::API, ['query' => $query, 'variables' => $variables]));
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if (! is_array($body['data'] ?? null) && is_array($body['errors'] ?? null)) {
            $first = $body['errors'][0] ?? null;

            throw new ProviderUnavailable('Hardcover answered: '.($this->text(is_array($first) ? ($first['message'] ?? null) : null) ?: 'a GraphQL error').'.');
        }

        return $body;
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
