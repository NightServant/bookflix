<?php

namespace App\Catalog\Ingestion;

use App\Catalog\Data\NormalizedMedia;
use App\Enums\AgeRating;
use Illuminate\Support\Str;

class TaxonomyMapper
{
    /**
     * Parts of a compound subject that say nothing on their own: "Fiction, fantasy, general" is just fantasy.
     */
    private const GENERIC_PARTS = ['fiction', 'general', 'literature', 'stories', 'story', 'novels', 'novel', 'books', 'texts'];

    /**
     * Words that wrap a subject without changing it ("Fantasy fiction", "Detective and mystery stories").
     */
    private const WRAPPERS = ['fiction', 'stories', 'novels', 'literature', 'books'];

    /**
     * Subjects that are catalogue housekeeping, not content (Open Library fills subjects with them): never worth a tag.
     */
    private const NOISE = '~^(in library|accessible book|protected daisy|lending library|internet archive wishlist|large type books|open library staff picks|reading level|new york times bestseller|nyt|overdrive|series|place|person|time|collectionid)\b~i';

    /**
     * Provider term lookups (`database/data/taxonomy/<provider>.csv`: `provider_term,kind,bookflix_slug`), by provider then normalized term.
     * A term can map to several nodes (a gore tag is a warning, a body horror tag a genre and a warning).
     *
     * kind: genre (a family), subgenre, theme, trope, warning (reported as present), demographic, rating (an AgeRating value), tag (keep as a tag).
     *
     * @var array<string, array<string, list<array{kind: string, slug: string}>>>
     */
    private array $mappings = [];

    /**
     * Bookflix taxonomy slugs for a record's provider terms. Terms the provider's CSV does not map become tags.
     */
    public function map(string $provider, NormalizedMedia $media): MappedTaxonomy
    {
        return $this->mapTerms($provider, [...$media->genres, ...$media->themes, ...$media->tags, ...$media->demographics], $media->contentWarnings);
    }

    /**
     * Resolve raw provider terms. `$warningTerms` are the ones the provider itself files as content warnings: only a warning mapping counts for
     * them, and one the vocabulary does not know is kept as a tag rather than lost. A term that names prohibited content is dropped outright.
     *
     * @param  list<string>  $terms
     * @param  list<string>  $warningTerms
     */
    public function mapTerms(string $provider, array $terms, array $warningTerms = []): MappedTaxonomy
    {
        $mapping = $this->mapping($provider);
        $mapped = ['genre' => [], 'theme' => [], 'trope' => [], 'demographic' => [], 'warning' => []];
        $tags = [];
        $rating = AgeRating::Unknown;

        $queue = [...array_map(fn (string $term): array => [$term, false], $terms), ...array_map(fn (string $term): array => [$term, true], $warningTerms)];

        foreach ($queue as [$term, $isWarning]) {
            $term = trim($term);

            if ($term === '' || ProhibitedContent::matches($term)) {
                continue;
            }

            $entries = $this->lookup($mapping, $term);
            $entries = $isWarning ? array_values(array_filter($entries, fn (array $entry): bool => $entry['kind'] === 'warning')) : $entries;
            $tag = $entries === [] ? Str::slug($term) : null;

            foreach ($entries as $entry) {
                match ($entry['kind']) {
                    'genre', 'subgenre' => $mapped['genre'][] = $entry['slug'],
                    'theme', 'trope', 'warning', 'demographic' => $mapped[$entry['kind']][] = $entry['slug'],
                    'rating' => $rating = $rating->stricter(AgeRating::tryFrom($entry['slug']) ?? AgeRating::Unknown),
                    default => $tag = Str::slug($entry['slug']),
                };
            }

            if ($tag !== null && $tag !== '' && ! $this->isNoise($term) && mb_strlen($term) <= 255 && mb_strlen($tag) <= 255) {
                $tags[$tag] ??= $term;
            }
        }

        return new MappedTaxonomy(
            genres: array_values(array_unique($mapped['genre'])),
            themes: array_values(array_unique($mapped['theme'])),
            tropes: array_values(array_unique($mapped['trope'])),
            demographics: array_values(array_unique($mapped['demographic'])),
            warnings: array_values(array_unique($mapped['warning'])),
            rating: $rating === AgeRating::Unknown ? null : $rating,
            tags: $tags,
        );
    }

    /**
     * Whether a mapping row exists for the term (exact, or by the fuzzy rules), whatever it maps to.
     */
    public function knows(string $provider, string $term): bool
    {
        return $this->lookup($this->mapping($provider), trim($term)) !== [];
    }

    /**
     * The nodes a term maps to: its own row first, then the compound-subject rules ("Fiction, fantasy, general" is fantasy), then the term
     * without a wrapper word ("Fantasy fiction") and without a plural s.
     *
     * @param  array<string, list<array{kind: string, slug: string}>>  $mapping
     * @return list<array{kind: string, slug: string}>
     */
    private function lookup(array $mapping, string $term): array
    {
        $key = $this->key($term);

        if (isset($mapping[$key])) {
            return $mapping[$key];
        }

        $raw = preg_split('~[,/]~', $term) ?: [];
        $parts = array_values(array_filter(array_map($this->key(...), $raw), fn (string $part): bool => $part !== '' && ! in_array($part, self::GENERIC_PARTS, true)));

        if (count($raw) > 1 && $parts !== []) {
            if (isset($mapping[implode(' ', $parts)])) {
                return $mapping[implode(' ', $parts)];
            }

            $union = [];

            foreach ($parts as $part) {
                $union = [...$union, ...($mapping[$part] ?? [])];
            }

            if ($union !== []) {
                return $union;
            }
        }

        $words = explode(' ', $key);
        $candidates = [];

        if (count($words) > 1 && in_array(end($words), self::WRAPPERS, true)) {
            $candidates[] = implode(' ', array_slice($words, 0, -1));
        }

        if (count($words) > 1 && in_array($words[0], self::WRAPPERS, true)) {
            $candidates[] = implode(' ', array_slice($words, 1));
        }

        if (mb_strlen($key) > 4 && str_ends_with($key, 's')) {
            $candidates[] = substr($key, 0, -1);
        }

        foreach ($candidates as $candidate) {
            if (isset($mapping[$candidate])) {
                return $mapping[$candidate];
            }
        }

        return [];
    }

    /**
     * Housekeeping subjects and compound subjects made only of generic words ("Fiction, general") are not worth a tag.
     */
    private function isNoise(string $term): bool
    {
        $parts = array_filter(array_map($this->key(...), preg_split('~[,/]~', $term) ?: []), fn (string $part): bool => $part !== '');

        return preg_match(self::NOISE, $term) === 1 || array_diff($parts, self::GENERIC_PARTS) === [];
    }

    /**
     * A term as the lookup compares it: lowercase, accents folded, `&` as "and", punctuation as single spaces.
     */
    private function key(string $term): string
    {
        $text = Str::lower(Str::ascii(str_replace('&', ' and ', $term)));

        return trim(preg_replace('~[^a-z0-9]+~', ' ', $text) ?? '');
    }

    /**
     * @return array<string, list<array{kind: string, slug: string}>>
     */
    private function mapping(string $provider): array
    {
        if (isset($this->mappings[$provider])) {
            return $this->mappings[$provider];
        }

        $path = database_path("data/taxonomy/{$provider}.csv");
        $lines = is_file($path) ? array_slice(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], 1) : [];
        $mapping = [];

        foreach ($lines as $line) {
            [$term, $kind, $slug] = str_getcsv($line, escape: '') + [null, null, null];

            if ($term === null || $kind === null || $slug === null) {
                continue;
            }

            $mapping[$this->key($term)][] = ['kind' => $kind, 'slug' => $slug];
        }

        return $this->mappings[$provider] = $mapping;
    }
}
