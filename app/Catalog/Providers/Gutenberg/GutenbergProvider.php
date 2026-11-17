<?php

namespace App\Catalog\Providers\Gutenberg;

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
use Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Project Gutenberg through its official CSV catalogue, never its pages. The file is downloaded once and kept on the private disk, so the
 * pages of a run read slices of the local copy instead of fetching it again. A page token is a row offset into the file.
 */
class GutenbergProvider implements MetadataProvider, OffersContentFiles
{
    use SanitizesProviderData, SendsProviderRequests;

    private const BASE_URL = 'https://www.gutenberg.org';

    private const CATALOG_URL = 'https://www.gutenberg.org/cache/epub/feeds/pg_catalog.csv.gz';

    private const DIRECTORY = 'catalog-feeds/gutenberg';

    private const FILE = self::DIRECTORY.'/pg_catalog.csv.gz';

    /**
     * Hours a downloaded catalogue is reused. Gutenberg regenerates it daily, so a run always starts from a recent copy.
     */
    private const MAX_AGE_HOURS = 20;

    private const COLUMNS = ['Text#', 'Type', 'Issued', 'Title', 'Language', 'Authors', 'Subjects', 'LoCC', 'Bookshelves'];

    private const MAX_CREATORS = 10;

    private const MAX_SUBJECTS = 10;

    private const LICENSE = 'Public domain in the USA (Project Gutenberg). Check the laws of your country before reading. Project Gutenberg License: https://www.gutenberg.org/policy/license.html';

    public function key(): string
    {
        return 'gutenberg';
    }

    /**
     * A slice of `page_size` catalogue rows starting at the token's offset. The Text rows among them issued since the cursor are the records:
     * the cursor is a date, and a book issued on the cursor's own day is kept, because the cursor carries the time of the last walk and a book
     * released later that day would otherwise never be seen.
     */
    public function page(?CarbonImmutable $since, ?string $pageToken): ProviderPage
    {
        $offset = max(0, (int) $pageToken);
        $size = max(1, (int) config('bookflix.providers.gutenberg.page_size', 500));
        $this->ensureCatalog();

        $records = [];
        $row = 0;
        $more = false;

        foreach ($this->rows() as $data) {
            if ($row >= $offset + $size) {
                $more = true;
                break;
            }

            if ($row++ < $offset || $data === null || ! $this->isText($data) || ! $this->issuedSince($data, $since)) {
                continue;
            }

            $records[] = $this->record($data);
        }

        return new ProviderPage($records, $more ? (string) ($offset + $size) : null, null);
    }

    public function find(string $externalId): ?ProviderRecord
    {
        $this->ensureCatalog();

        foreach ($this->rows() as $data) {
            if ($data !== null && $data['Text#'] === $externalId && $this->isText($data)) {
                return $this->record($data);
            }
        }

        return null;
    }

    public function normalize(ProviderRecord $record): NormalizedMedia
    {
        $row = $record->payload;
        $subjects = $this->terms($row['Subjects'] ?? null);
        $shelves = $this->terms($row['Bookshelves'] ?? null);
        $fiction = str_starts_with($this->text($row['LoCC'] ?? null), 'P')
            || preg_grep('/(?<!non-)\bfiction\b/i', [...$shelves, ...$subjects]) !== [];

        return new NormalizedMedia(
            title: $this->text($row['Title'] ?? null),
            mediaTypeSlug: $fiction ? 'novel' : 'nonfiction',
            creators: $this->creators($this->text($row['Authors'] ?? null)),
            genres: array_slice($subjects, 0, self::MAX_SUBJECTS),
            // Issued is the day Gutenberg released the book, not when it was written, so there is no publication year.
            publicationYear: null,
            language: $this->language($this->text($row['Language'] ?? null)),
            sourceUrl: $this->safeUrl($record->sourceUrl),
        );
    }

    /**
     * Gutenberg builds an EPUB with images for every book at a fixed path; whether the book really is public domain is checked against its
     * own record before anything is downloaded.
     */
    public function contentFile(ProviderRecord $record): ?ContentFileOffer
    {
        $id = $record->externalId;

        if (! ctype_digit($id)) {
            return null;
        }

        $url = $this->safeUrl(self::BASE_URL."/cache/epub/{$id}/pg{$id}-images-3.epub");

        return $url === null ? null : new ContentFileOffer("gutenberg:{$id}", $url, ContentFormat::Epub, self::LICENSE);
    }

    /**
     * Download the catalogue unless a copy younger than MAX_AGE_HOURS is on the private disk. It is streamed to a temporary file and moved
     * into place, so a failed download never replaces a good copy.
     *
     * @throws RateLimitedByProvider
     * @throws ProviderUnavailable
     */
    private function ensureCatalog(): void
    {
        $disk = Storage::disk('local');

        if ($disk->exists(self::FILE) && $disk->lastModified(self::FILE) > now()->subHours(self::MAX_AGE_HOURS)->getTimestamp()) {
            return;
        }

        $disk->makeDirectory(self::DIRECTORY);
        $temporary = self::DIRECTORY.'/pg_catalog.'.bin2hex(random_bytes(6)).'.part';

        try {
            $this->send(fn () => $this->client()->timeout(600)->withOptions(['sink' => $disk->path($temporary)])->get(self::CATALOG_URL));
            $disk->move($temporary, self::FILE);
        } finally {
            $disk->delete($temporary);
        }
    }

    /**
     * The catalogue rows keyed by column, one at a time, straight from the compressed file. A malformed row is logged and yielded as null so it still
     * takes its place in the offsets and one bad line cannot move the pages after it.
     *
     * @return Generator<int, array<string, string>|null>
     */
    private function rows(): Generator
    {
        $handle = @fopen('compress.zlib://'.Storage::disk('local')->path(self::FILE), 'rb');

        if ($handle === false) {
            throw new ProviderUnavailable('The Project Gutenberg catalogue could not be opened.');
        }

        try {
            $header = fgetcsv($handle, escape: '');
            $header = is_array($header) ? array_map(fn ($name) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $name)), $header) : [];

            if (array_diff(self::COLUMNS, $header) !== []) {
                throw new ProviderUnavailable('The Project Gutenberg catalogue does not have the expected columns.');
            }

            while (($line = fgetcsv($handle, escape: '')) !== false) {
                if ($line === [null]) {
                    continue;
                }

                if (count($line) !== count($header) || ! ctype_digit(trim((string) $line[array_search('Text#', $header, true)]))) {
                    Log::warning('A Project Gutenberg catalogue row was skipped because it is malformed.', ['provider' => 'gutenberg', 'row' => mb_substr(implode(',', array_map('strval', $line)), 0, 200)]);
                    yield null;

                    continue;
                }

                yield array_map(fn ($value) => (string) $value, array_combine($header, $line));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function record(array $row): ProviderRecord
    {
        $id = trim($row['Text#']);

        return new ProviderRecord($this->key(), $id, array_intersect_key($row, array_flip(self::COLUMNS)), self::BASE_URL."/ebooks/{$id}", CarbonImmutable::now());
    }

    /**
     * @param  array<string, string>  $row
     */
    private function isText(array $row): bool
    {
        return trim($row['Type']) === 'Text';
    }

    /**
     * @param  array<string, string>  $row
     */
    private function issuedSince(array $row, ?CarbonImmutable $since): bool
    {
        if ($since === null) {
            return true;
        }

        try {
            return CarbonImmutable::parse(trim($row['Issued']), 'UTC')->endOfDay()->gt($since);
        } catch (\Throwable) {
            // A date we cannot read is kept: importing a book twice costs nothing, missing it does.
            return true;
        }
    }

    /**
     * "Austen, Jane, 1775-1817; Tenniel, John, 1820-1914 [Illustrator]": people split on semicolons, "Last, First" with the life dates dropped,
     * and an optional role in brackets. No role means an author; roles the catalogue does not model (editor, compiler...) are left out.
     *
     * @return list<NormalizedCreator>
     */
    private function creators(string $authors): array
    {
        $creators = [];

        foreach (explode(';', $authors) as $person) {
            if (! preg_match('/^(?<name>[^\[]*?)\s*(?:\[(?<role>[^\]]*)\])?\s*$/u', trim($person), $match) || trim($match['name']) === '') {
                continue;
            }

            $role = match (strtolower(trim($match['role'] ?? ''))) {
                '' => CreatorRole::Author,
                'illustrator' => CreatorRole::Illustrator,
                'translator' => CreatorRole::Translator,
                default => null,
            };

            $parts = array_values(array_filter(
                array_map('trim', explode(',', $match['name'])),
                fn (string $part) => $part !== '' && ! preg_match('/\d/', $part),
            ));
            $name = count($parts) >= 2 ? implode(' ', [$parts[1], $parts[0], ...array_slice($parts, 2)]) : ($parts[0] ?? '');

            if ($role !== null && $name !== '') {
                $creators[] = new NormalizedCreator($name, $role);
            }
        }

        return array_slice($creators, 0, self::MAX_CREATORS);
    }

    /**
     * The first language code of "en; fr", when it is a two-letter ISO 639-1 code.
     */
    private function language(string $languages): ?string
    {
        $first = strtolower(trim(explode(';', $languages)[0]));

        return preg_match('/^[a-z]{2}$/', $first) ? $first : null;
    }

    /**
     * @return list<string>
     */
    private function terms(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $this->text($value))), fn (string $term) => $term !== ''));
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
