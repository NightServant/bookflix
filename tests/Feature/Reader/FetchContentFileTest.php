<?php

use App\Actions\Reader\VerifyContentSource;
use App\Enums\ContentStatus;
use App\Jobs\FetchContentFile;
use App\Models\ContentSource;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Fetching a verified public-domain file (P9-R5, P9-R7): the host, the rights record, the size, the type and the archive are all checked, and a
 * refusal leaves the source failed with a short reason and nothing on the disk.
 */

const EPUB_URL = 'https://www.gutenberg.org/cache/epub/84/pg84-images-3.epub';
const RDF_URL = 'https://www.gutenberg.org/cache/epub/84/pg84.rdf';

beforeEach(function () {
    Storage::fake(ContentSource::disk());
});

function epubBytes(): string
{
    $path = tempnam(sys_get_temp_dir(), 'epub');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('mimetype', 'application/epub+zip');
    $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
    $zip->addFromString('META-INF/container.xml', '<container/>');
    $zip->addFromString('OEBPS/chapter.xhtml', '<html><body>Hello</body></html>');
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

function rdf(string $rights): string
{
    return '<?xml version="1.0" encoding="utf-8"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:pgterms="http://www.gutenberg.org/2009/pgterms/">'
        ."<pgterms:ebook rdf:about=\"ebooks/84\"><dcterms:rights>{$rights}</dcterms:rights></pgterms:ebook></rdf:RDF>";
}

function pdSource(array $attributes = []): ContentSource
{
    return ContentSource::factory()->verified()->create(['external_key' => 'gutenberg:84', 'url' => EPUB_URL, ...$attributes]);
}

function fetchTempFiles(): array
{
    return glob(sys_get_temp_dir().'/bookflix-fetch-*') ?: [];
}

test('it stores a valid epub on the private disk and marks the source ready', function () {
    Http::fake([RDF_URL => Http::response(rdf('Public domain in the USA.')), EPUB_URL => Http::response($body = epubBytes())]);
    $source = pdSource();

    (new FetchContentFile($source->id))->handle();

    $source->refresh();
    expect($source->status)->toBe(ContentStatus::Ready)
        ->and($source->failure_reason)->toBeNull()
        ->and($source->byte_size)->toBe(strlen($body))
        ->and($source->checksum)->toBe(hash('sha256', $body))
        ->and($source->mime_type)->toBe('application/epub+zip')
        ->and($source->storage_path)->toMatch('#^content/pd/[0-9A-Z]{26}\.epub$#');
    Storage::disk(ContentSource::disk())->assertExists($source->storage_path);
    expect(Storage::disk(ContentSource::disk())->get($source->storage_path))->toBe($body);
    Http::assertSent(fn (Request $request) => $request->url() === EPUB_URL && str_contains($request->header('User-Agent')[0], 'Bookflix'));
});

test('a copyrighted gutenberg book is failed before any download', function () {
    Http::fake([RDF_URL => Http::response(rdf('Copyrighted. Read the copyright notice inside this book for details.'))]);
    $source = pdSource();

    (new FetchContentFile($source->id))->handle();

    $source->refresh();
    expect($source->status)->toBe(ContentStatus::Failed)
        ->and($source->failure_reason)->toBe('Project Gutenberg marks this book as copyrighted.')
        ->and($source->storage_path)->toBeNull();
    Http::assertNotSent(fn (Request $request) => $request->url() === EPUB_URL);
});

test('a host outside the provider is refused without any request', function () {
    Http::fake();
    $source = pdSource(['url' => 'https://evil.example.com/book.epub']);

    (new FetchContentFile($source->id))->handle();

    expect($source->refresh()->status)->toBe(ContentStatus::Failed)->and($source->failure_reason)->toContain('address');
    Http::assertNothingSent();
});

test('an oversize file is refused', function () {
    config(['bookflix.reader.fetch.max_mb' => 1]);
    Http::fake([RDF_URL => Http::response(rdf('Public domain in the USA.')), EPUB_URL => Http::response(random_bytes(1048577))]);
    $source = pdSource();

    (new FetchContentFile($source->id))->handle();

    expect($source->refresh()->status)->toBe(ContentStatus::Failed)->and($source->failure_reason)->toBe('The file is too large.')->and($source->storage_path)->toBeNull();
    expect(Storage::disk(ContentSource::disk())->allFiles())->toBe([]);
});

test('a declared content length above the cap is refused', function () {
    config(['bookflix.reader.fetch.max_mb' => 1]);
    Http::fake([RDF_URL => Http::response(rdf('Public domain in the USA.')), EPUB_URL => Http::response('x', 200, ['Content-Length' => 5000000])]);
    $source = pdSource();

    (new FetchContentFile($source->id))->handle();

    expect($source->refresh()->status)->toBe(ContentStatus::Failed)->and($source->failure_reason)->toBe('The file is too large.');
});

test('html served as an epub is refused', function () {
    Http::fake([RDF_URL => Http::response(rdf('Public domain in the USA.')), EPUB_URL => Http::response('<html><body>Please log in</body></html>')]);
    $source = pdSource();

    (new FetchContentFile($source->id))->handle();

    expect($source->refresh()->status)->toBe(ContentStatus::Failed)->and($source->failure_reason)->toBe('The file is not an EPUB.');
    expect(Storage::disk(ContentSource::disk())->allFiles())->toBe([]);
});

test('a zip that is not an epub is refused', function () {
    $path = tempnam(sys_get_temp_dir(), 'zipx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'just a zip');
    $zip->close();
    Http::fake([RDF_URL => Http::response(rdf('Public domain in the USA.')), EPUB_URL => Http::response(file_get_contents($path))]);
    unlink($path);
    $source = pdSource();

    (new FetchContentFile($source->id))->handle();

    expect($source->refresh()->status)->toBe(ContentStatus::Failed)->and($source->failure_reason)->toContain('not a valid EPUB');
    expect(Storage::disk(ContentSource::disk())->allFiles())->toBe([]);
});

test('a missing file fails with the address message', function () {
    Http::fake([RDF_URL => Http::response(rdf('Public domain in the USA.')), EPUB_URL => Http::response('', 404)]);
    $source = pdSource();

    (new FetchContentFile($source->id))->handle();

    expect($source->refresh()->status)->toBe(ContentStatus::Failed)->and($source->failure_reason)->toBe('The file is no longer at its address.');
});

test('a standard ebooks file needs no rights record', function () {
    $url = 'https://standardebooks.org/ebooks/jane-austen/emma/downloads/jane-austen_emma.epub';
    Http::fake([$url => Http::response(epubBytes())]);
    $source = pdSource(['external_key' => 'standardebooks:jane-austen/emma', 'url' => $url]);

    (new FetchContentFile($source->id))->handle();

    expect($source->refresh()->status)->toBe(ContentStatus::Ready);
});

test('a ready source whose file is present is left alone', function () {
    Http::fake();
    $source = pdSource();
    $ready = ContentSource::factory()->verified()->ready()->create(['external_key' => 'gutenberg:85', 'url' => 'https://www.gutenberg.org/cache/epub/85/pg85-images-3.epub']);

    (new FetchContentFile($ready->id))->handle();

    Http::assertNothingSent();
    expect($ready->refresh()->status)->toBe(ContentStatus::Ready)->and($source->refresh()->status)->toBe(ContentStatus::Pending);
});

test('an unverified source is left alone', function () {
    Http::fake();
    $source = ContentSource::factory()->create(['external_key' => 'gutenberg:84', 'url' => EPUB_URL]);

    (new FetchContentFile($source->id))->handle();

    Http::assertNothingSent();
    expect($source->refresh()->status)->toBe(ContentStatus::Pending);
});

test('temporary files are removed whatever the outcome', function () {
    Http::fake([RDF_URL => Http::response(rdf('Public domain in the USA.')), 'https://www.gutenberg.org/cache/epub/85/pg85.rdf' => Http::response(rdf('Public domain in the USA.')), EPUB_URL => Http::response(epubBytes()), 'https://www.gutenberg.org/cache/epub/85/*' => Http::response('<html></html>')]);
    $before = fetchTempFiles();

    (new FetchContentFile(pdSource()->id))->handle();
    (new FetchContentFile(pdSource(['external_key' => 'gutenberg:85', 'url' => 'https://www.gutenberg.org/cache/epub/85/pg85-images-3.epub'])->id))->handle();

    expect(fetchTempFiles())->toBe($before);
});

test('verifying a public domain source queues the fetch, and saving a failed one again retries it', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $source = ContentSource::factory()->create(['external_key' => 'gutenberg:84', 'url' => EPUB_URL]);

    app(VerifyContentSource::class)->handle($source, $admin, 'Public domain in the USA', null);
    Queue::assertPushed(FetchContentFile::class, 1);
    Queue::assertPushedOn('ingestion', FetchContentFile::class);

    $source->forceFill(['status' => ContentStatus::Failed, 'failure_reason' => 'The file is not an EPUB.'])->save();
    Cache::lock('laravel_unique_job:'.FetchContentFile::class.':'.$source->id)->forceRelease(); // what a finished job would have released
    app(VerifyContentSource::class)->handle($source, $admin, 'Public domain in the USA', null);
    Queue::assertPushed(FetchContentFile::class, 2);
});

test('verifying a ready source or one without an address queues nothing', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();

    app(VerifyContentSource::class)->handle(ContentSource::factory()->ready()->create(['url' => EPUB_URL]), $admin, 'Public domain', null);
    app(VerifyContentSource::class)->handle(ContentSource::factory()->create(), $admin, 'Public domain', null);

    Queue::assertNothingPushed();
});
