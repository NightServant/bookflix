<?php

use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\IngestionPipeline;
use App\Catalog\Providers\Gutenberg\GutenbergProvider;
use App\Catalog\Providers\ProviderUnavailable;
use App\Catalog\Providers\StandardEbooks\StandardEbooksProvider;
use App\Enums\AccessType;
use App\Enums\ContentFormat;
use App\Enums\ContentStatus;
use App\Enums\CreatorRole;
use App\Enums\IngestionMode;
use App\Jobs\FetchProviderPage;
use App\Models\ContentSource;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use App\Models\Media;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\MediaTypeSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * The public-domain catalogues are providers like the others (P9-R6, P9-R7): the pipeline imports the media, and each offered file is recorded as an
 * unverified content source. Only the two feed URLs are faked, and stray requests are refused, so anything else would fail the test.
 */

const GUTENBERG_FEED = 'https://www.gutenberg.org/cache/epub/feeds/pg_catalog.csv.gz';
const STANDARD_EBOOKS_FEED = 'https://standardebooks.org/feeds/atom/new-releases';

/**
 * @param  list<list<string>>  $rows
 */
function gutenbergCsv(array $rows): string
{
    $handle = fopen('php://memory', 'w+');
    fputcsv($handle, ['Text#', 'Type', 'Issued', 'Title', 'Language', 'Authors', 'Subjects', 'LoCC', 'Bookshelves'], escape: '');

    foreach ($rows as $row) {
        fputcsv($handle, $row, escape: '');
    }

    rewind($handle);

    return gzencode((string) stream_get_contents($handle));
}

/**
 * @return list<list<string>>
 */
function gutenbergRows(): array
{
    return [
        ['11', 'Text', '2024-01-10', "Alice's Adventures in Wonderland", 'en', 'Carroll, Lewis, 1832-1898; Tenniel, John, 1820-1914 [Illustrator]', 'Fantasy fiction', 'PZ', "Children's Literature"],
        ['1342', 'Text', '2024-03-01', 'Pride and Prejudice', 'en', 'Austen, Jane, 1775-1817', 'Courtship -- Fiction', 'PR', 'Best Books Ever Listings'],
        ['5', 'Sound', '2024-04-01', 'A Spoken Recording', 'en', 'Reader, Some', '', '', ''],
        ['2009', 'Text', '2025-06-01', 'On the Origin of Species', 'en; fr', 'Darwin, Charles, 1809-1882', 'Evolution (Biology)', 'QH', 'Biology'],
        ['3000', 'Text', '2025-07-01', 'A Study of Soils', 'en', 'Roe, Jane', 'Soil science', 'S', ''],
    ];
}

function fakeGutenberg(?array $rows = null): void
{
    $csv = gutenbergCsv($rows ?? gutenbergRows());

    // A closure builds a fresh response each time, so a second download of the same body is not read from a spent stream.
    Http::fake([GUTENBERG_FEED => fn () => Http::response($csv)]);
}

function standardEbooksFeed(): string
{
    return file_get_contents(__DIR__.'/../../Fixtures/providers/standardebooks/new-releases.atom');
}

function runProvider(string $key, ?string $since = null): IngestionRun
{
    app(MediaTypeSeeder::class)->run();
    $source = ExternalSource::where('key', $key)->first() ?? ExternalSource::factory()->create(['key' => $key, 'enabled' => true]);
    $run = IngestionRun::create(['external_source_id' => $source->id, 'mode' => $since === null ? IngestionMode::Full : IngestionMode::Incremental, 'started_at' => now()]);

    if ($since !== null) {
        $source->update(['sync_cursor' => ['since' => $since]]);
    }

    Queue::fake();
    (new FetchProviderPage($run->id))->handle(app(IngestionPipeline::class));

    return $run->fresh();
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake(ContentSource::disk());
});

test('gutenberg creates media and unverified sources through the pipeline', function () {
    fakeGutenberg();

    $run = runProvider('gutenberg');

    expect($run->counts['created'])->toBe(4)
        ->and(Media::count())->toBe(4)
        ->and(ContentSource::count())->toBe(4);

    $alice = Media::where('title', "Alice's Adventures in Wonderland")->firstOrFail();
    $source = ContentSource::where('media_id', $alice->id)->firstOrFail();

    expect($source->external_key)->toBe('gutenberg:11')
        ->and($source->access_type)->toBe(AccessType::PublicDomain)
        ->and($source->format)->toBe(ContentFormat::Epub)
        ->and($source->url)->toBe('https://www.gutenberg.org/cache/epub/11/pg11-images-3.epub')
        ->and($source->license)->toContain('Project Gutenberg License')
        ->and($source->status)->toBe(ContentStatus::Pending)
        ->and($source->verified_at)->toBeNull()
        ->and($source->verified_by)->toBeNull()
        ->and($source->isVerified())->toBeFalse()
        ->and($alice->mediaType->slug)->toBe('novel')
        ->and($alice->publication_year)->toBeNull()
        ->and($alice->creators->pluck('name'))->toContain('Lewis Carroll', 'John Tenniel');
    expect(Media::where('title', 'On the Origin of Species')->firstOrFail()->mediaType->slug)->toBe('nonfiction');
});

test('gutenberg normalizes authors, roles, language and type', function () {
    fakeGutenberg();
    $provider = app(GutenbergProvider::class);
    $record = new ProviderRecord('gutenberg', '11', array_combine(
        ['Text#', 'Type', 'Issued', 'Title', 'Language', 'Authors', 'Subjects', 'LoCC', 'Bookshelves'],
        gutenbergRows()[0],
    ), 'https://www.gutenberg.org/ebooks/11', CarbonImmutable::now());

    $media = $provider->normalize($record);

    expect($media->creators[0]->name)->toBe('Lewis Carroll')
        ->and($media->creators[0]->role)->toBe(CreatorRole::Author)
        ->and($media->creators[1]->name)->toBe('John Tenniel')
        ->and($media->creators[1]->role)->toBe(CreatorRole::Illustrator)
        ->and($media->language)->toBe('en')
        ->and($media->publicationYear)->toBeNull()
        ->and($media->sourceUrl)->toBe('https://www.gutenberg.org/ebooks/11');

    $darwin = $provider->find('2009');
    expect($provider->normalize($darwin)->language)->toBe('en')
        ->and($provider->normalize($darwin)->mediaTypeSlug)->toBe('nonfiction')
        ->and($provider->find('5'))->toBeNull()
        ->and($provider->find('999999'))->toBeNull();
});

test('a fiction shelf makes a novel even without a Library of Congress class', function () {
    fakeGutenberg([['77', 'Text', '2024-01-01', 'Odd Tales', 'en', 'Poe, Edgar Allan', 'Short stories', '', 'Science Fiction']]);

    expect(app(GutenbergProvider::class)->normalize(app(GutenbergProvider::class)->find('77'))->mediaTypeSlug)->toBe('novel');
});

test('importing again creates no duplicates', function () {
    fakeGutenberg();
    runProvider('gutenberg');
    runProvider('gutenberg');

    expect(Media::count())->toBe(4)->and(ContentSource::count())->toBe(4);

    Http::fake([STANDARD_EBOOKS_FEED => Http::response(standardEbooksFeed())]);
    runProvider('standardebooks');
    runProvider('standardebooks');

    expect(ContentSource::where('external_key', 'like', 'standardebooks:%')->count())->toBe(3);
});

test('non text rows are skipped', function () {
    fakeGutenberg();
    $page = app(GutenbergProvider::class)->page(null, null);

    expect(collect($page->records)->pluck('externalId')->all())->toBe(['11', '1342', '2009', '3000']);
});

test('the since cursor keeps books issued on or after its day', function () {
    fakeGutenberg();
    $page = app(GutenbergProvider::class)->page(CarbonImmutable::parse('2025-06-01T13:00:00Z'), null);

    expect(collect($page->records)->pluck('externalId')->all())->toBe(['2009', '3000']);

    $newer = app(GutenbergProvider::class)->page(CarbonImmutable::parse('2025-06-02T00:00:00Z'), null);
    expect(collect($newer->records)->pluck('externalId')->all())->toBe(['3000']);
});

test('pages are slices of rows and the token is a byte offset into the unpacked catalogue', function () {
    fakeGutenberg();
    config(['bookflix.providers.gutenberg.page_size' => 2]);
    $provider = app(GutenbergProvider::class);

    $first = $provider->page(null, null);
    $second = $provider->page(null, $first->nextPageToken);
    $third = $provider->page(null, $second->nextPageToken);

    $csv = (string) Storage::disk('local')->get('catalog-feeds/gutenberg/pg_catalog.csv');
    $secondStart = strpos($csv, "\n5,Sound") + 1;

    expect($first->nextPageToken)->toMatch('/^[0-9a-f]{10}:'.$secondStart.'$/')
        ->and(collect($first->records)->pluck('externalId')->all())->toBe(['11', '1342'])
        ->and($second->nextPageToken)->toMatch('/^[0-9a-f]{10}:\d+$/')
        ->and(collect($second->records)->pluck('externalId')->all())->toBe(['2009'])
        ->and(collect($third->records)->pluck('externalId')->all())->toBe(['3000'])
        ->and($third->nextPageToken)->toBeNull();
});

test('a token that is not a byte offset restarts the walk from the first row', function () {
    fakeGutenberg();
    config(['bookflix.providers.gutenberg.page_size' => 2]);
    $provider = app(GutenbergProvider::class);

    $signature = explode(':', (string) $provider->page(null, null)->nextPageToken)[0];

    // '2' is what an older run left behind (a bare offset); the others have the right signature with a wrong offset, or are not tokens.
    foreach (['2', "{$signature}:2", "{$signature}:99999999", 'junk'] as $token) {
        expect(collect($provider->page(null, $token)->records)->pluck('externalId')->all())->toBe(['11', '1342']);
    }
});

test('a token made before the catalogue was downloaded again restarts from the first row', function () {
    fakeGutenberg();
    config(['bookflix.providers.gutenberg.page_size' => 2]);
    $provider = app(GutenbergProvider::class);

    $first = $provider->page(null, null);

    // The same file, as the next download would leave it: a new modification time and a new size.
    $path = Storage::disk('local')->path('catalog-feeds/gutenberg/pg_catalog.csv');
    file_put_contents($path, "\n", FILE_APPEND);
    touch($path, time() + 60);

    expect(collect($provider->page(null, $first->nextPageToken)->records)->pluck('externalId')->all())->toBe(['11', '1342']);
});

test('the catalogue is downloaded once per run and again when it is old', function () {
    fakeGutenberg();
    config(['bookflix.providers.gutenberg.page_size' => 2]);
    $provider = app(GutenbergProvider::class);

    $first = $provider->page(null, null);
    $second = $provider->page(null, $first->nextPageToken);
    $provider->page(null, $second->nextPageToken);
    $provider->find('3000');

    Http::assertSentCount(1);
    Storage::disk('local')->assertExists('catalog-feeds/gutenberg/pg_catalog.csv.gz');
    Storage::disk('local')->assertExists('catalog-feeds/gutenberg/pg_catalog.csv');
    expect(Storage::disk('local')->allFiles('catalog-feeds/gutenberg'))->toHaveCount(2);

    touch(Storage::disk('local')->path('catalog-feeds/gutenberg/pg_catalog.csv.gz'), now()->subHours(21)->getTimestamp());
    $provider->page(null, null);

    Http::assertSentCount(2);
});

test('a malformed catalogue row is logged and skipped without aborting the page', function () {
    Log::spy();
    $rows = gutenbergRows();
    array_splice($rows, 1, 0, [['oops', 'Text']]);
    fakeGutenberg($rows);

    $run = runProvider('gutenberg');

    expect($run->counts['created'])->toBe(4)->and($run->counts['failed'])->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'malformed'))->once();
});

test('a prohibited term still keeps a record out, so no source is recorded for it', function () {
    fakeGutenberg([['9', 'Text', '2024-01-01', 'Bad Book', 'en', 'Roe, Jane', 'Child pornography', 'PZ', '']]);

    runProvider('gutenberg');

    expect(Media::count())->toBe(0)->and(ContentSource::count())->toBe(0);
});

test('standard ebooks creates media and unverified sources from the recommended epub', function () {
    Http::fake([STANDARD_EBOOKS_FEED => Http::response(standardEbooksFeed())]);

    $run = runProvider('standardebooks');

    expect($run->counts['created'])->toBe(3)->and(ContentSource::count())->toBe(3);

    $source = ContentSource::where('external_key', 'standardebooks:edgar-rice-burroughs/a-fighting-man-of-mars')->firstOrFail();
    $media = $source->media;

    expect($source->url)->toBe('https://standardebooks.org/ebooks/edgar-rice-burroughs/a-fighting-man-of-mars/downloads/edgar-rice-burroughs_a-fighting-man-of-mars.epub?source=feed')
        ->and($source->url)->not->toContain('advanced')->not->toContain('kepub')
        ->and($source->license)->toStartWith('Public domain in the United States.')
        ->and($source->access_type)->toBe(AccessType::PublicDomain)
        ->and($source->isVerified())->toBeFalse()
        ->and($media->title)->toBe('A Fighting Man of Mars')
        ->and($media->mediaType->slug)->toBe('novel')
        ->and($media->description)->toContain('courageous warrior')
        ->and($media->creators->pluck('name'))->toContain('Edgar Rice Burroughs');
    Http::assertSentCount(1);
});

test('an entry without an epub link gets its media but no source', function () {
    Http::fake([STANDARD_EBOOKS_FEED => Http::response(preg_replace('/title="Recommended compatible epub"/', 'title="Something else"', standardEbooksFeed(), 1))]);

    $run = runProvider('standardebooks');

    expect($run->counts['created'])->toBe(3)
        ->and(ContentSource::count())->toBe(2)
        ->and(ContentSource::where('external_key', 'standardebooks:edgar-rice-burroughs/a-fighting-man-of-mars')->exists())->toBeFalse();
});

test('a malformed atom entry is logged and skipped without aborting the page', function () {
    Log::spy();
    Http::fake([STANDARD_EBOOKS_FEED => Http::response(preg_replace('#<title>A Fighting Man of Mars</title>#', '', standardEbooksFeed(), 1))]);

    $run = runProvider('standardebooks');

    expect($run->counts['created'])->toBe(2)->and($run->counts['failed'])->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'malformed'))->once();
});

test('the atom feed is parsed without entities or network', function () {
    $feed = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE feed [<!ENTITY secret SYSTEM "file:///etc/hostname">]>
<feed xmlns="http://www.w3.org/2005/Atom"><entry>
<id>https://standardebooks.org/ebooks/a-writer/a-book</id><title>A Book &secret;</title><rights>Public domain.</rights>
</entry></feed>
XML;
    Http::fake([STANDARD_EBOOKS_FEED => Http::response($feed)]);

    $record = app(StandardEbooksProvider::class)->find('a-writer/a-book');

    expect($record->payload['title'])->toBe('A Book');
});

test('a feed that is not xml is unavailable rather than imported', function () {
    Http::fake([STANDARD_EBOOKS_FEED => Http::response('<html><body>nope')]);

    expect(fn () => app(StandardEbooksProvider::class)->page(null, null))->toThrow(ProviderUnavailable::class);
});

test('only the feed hosts are requested', function () {
    fakeGutenberg();
    Http::fake([STANDARD_EBOOKS_FEED => Http::response(standardEbooksFeed())]);
    runProvider('gutenberg');
    runProvider('standardebooks');

    Http::assertSent(fn ($request) => in_array(parse_url($request->url(), PHP_URL_HOST), ['www.gutenberg.org', 'standardebooks.org'], true));
    expect(Http::recorded()->every(fn ($pair) => in_array(parse_url($pair[0]->url(), PHP_URL_HOST), ['www.gutenberg.org', 'standardebooks.org'], true)))->toBeTrue();
});

test('a verified source keeps its license, verification and url when the book is imported again', function () {
    fakeGutenberg();
    runProvider('gutenberg');

    $admin = User::factory()->create();
    $source = ContentSource::where('external_key', 'gutenberg:11')->firstOrFail();
    $source->update(['license' => 'Checked by an admin', 'rights_holder' => 'Nobody', 'verified_by' => $admin->id, 'verified_at' => now(), 'url' => 'https://www.gutenberg.org/old.epub']);

    runProvider('gutenberg');
    $source->refresh();

    expect($source->license)->toBe('Checked by an admin')
        ->and($source->rights_holder)->toBe('Nobody')
        ->and($source->verified_by)->toBe($admin->id)
        ->and($source->verified_at)->not->toBeNull()
        ->and($source->url)->toBe('https://www.gutenberg.org/old.epub')
        ->and(ContentSource::count())->toBe(4);
});
