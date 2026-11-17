<?php

use App\Models\ContentSource;
use App\Reader\Archives\UnsafeArchive;
use App\Reader\Cbz\CbzExtractor;
use Database\Factories\ContentSourceFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/*
 * The CBZ page index (P9-R10): which entries of a comic zip are pages, in reading order. Hostile and broken archives are refused with a friendly message.
 */

/**
 * Store a zip built from the given entries as a ready CBZ source.
 *
 * @param  array<string, string>  $files
 * @param  (callable(ZipArchive): void)|null  $after
 */
function cbzSource(array $files, ?callable $after = null): ContentSource
{
    $temp = (string) tempnam(sys_get_temp_dir(), 'cbzt');
    $zip = new ZipArchive;
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
    }

    if ($after !== null) {
        $after($zip);
    }

    $zip->close();
    $body = (string) file_get_contents($temp);
    unlink($temp);

    return storeCbz($body);
}

function storeCbz(string $body): ContentSource
{
    $source = ContentSource::factory()->publicDomain()->verified()->ready()->create(['format' => 'cbz']);
    Storage::disk(ContentSource::disk())->put($source->storage_path, $body);

    return $source;
}

beforeEach(function () {
    Storage::fake(ContentSource::disk());
    Cache::flush();
    $this->png = ContentSourceFactory::png(2);
});

test('pages are natural sorted so 2 comes before 10', function () {
    $source = cbzSource(['10.jpg' => $this->png, '2.jpg' => $this->png, '1.jpg' => $this->png, 'ch2/01.png' => $this->png, 'ch10/01.png' => $this->png]);

    $index = app(CbzExtractor::class)->index($source);

    expect($index->count())->toBe(5)
        ->and(array_map(fn ($p) => $p->name, $index->pages))->toBe(['1.jpg', '2.jpg', '10.jpg', 'ch2/01.png', 'ch10/01.png'])
        ->and($index->page(1)->name)->toBe('1.jpg')
        ->and($index->page(3)->size)->toBe(strlen($this->png))
        ->and($index->page(0))->toBeNull()
        ->and($index->page(6))->toBeNull();
});

test('only image files that are pages are kept', function () {
    $source = cbzSource([
        'b.JPG' => $this->png,
        'a.webp' => $this->png,
        'c.gif' => $this->png,
        'd.jpeg' => $this->png,
        '__MACOSX/._a.jpg' => $this->png,
        '__MACOSX/x/b.jpg' => $this->png,
        '.hidden.jpg' => $this->png,
        'sub/.DS_Store' => 'x',
        'sub/.thumb.png' => $this->png,
        'notes.txt' => 'hello',
        'ComicInfo.xml' => '<x/>',
        'page.pdf' => '%PDF',
        'folder/' => '',
    ]);

    $index = app(CbzExtractor::class)->index($source);

    expect(array_map(fn ($p) => $p->name, $index->pages))->toBe(['a.webp', 'b.JPG', 'c.gif', 'd.jpeg']);
});

test('unsafe entries are refused', function (string $name) {
    $source = cbzSource(['1.jpg' => $this->png, $name => $this->png]);

    expect(fn () => app(CbzExtractor::class)->index($source))->toThrow(UnsafeArchive::class, 'This file is not a safe archive');
})->with(['zip-slip' => '../../evil.jpg', 'absolute' => '/etc/evil.jpg']);

test('a symlink entry is refused', function () {
    $source = cbzSource(['1.jpg' => $this->png, 'link.jpg' => '1.jpg'], function (ZipArchive $zip) {
        $zip->setExternalAttributesName('link.jpg', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    });

    expect(fn () => app(CbzExtractor::class)->index($source))->toThrow(UnsafeArchive::class, 'link');
});

test('zip bomb limits are refused', function (string $key, int $value) {
    config(["bookflix.reader.archive.{$key}" => $value]);
    $source = cbzSource(['1.jpg' => $this->png, '2.jpg' => $this->png, '3.jpg' => $this->png]);

    expect(fn () => app(CbzExtractor::class)->index($source))->toThrow(UnsafeArchive::class);
})->with(['too many entries' => ['max_entries', 2], 'too much data' => ['max_uncompressed_mb', 0]]);

test('a ratio bomb is refused', function () {
    config(['bookflix.reader.archive.max_ratio' => 10]);
    $source = cbzSource(['1.jpg' => str_repeat('A', 3 * 1048576)]);

    expect(fn () => app(CbzExtractor::class)->index($source))->toThrow(UnsafeArchive::class, 'far larger');
});

test('an empty zip is refused', function () {
    $source = storeCbz("PK\x05\x06".str_repeat("\0", 18));

    expect(fn () => app(CbzExtractor::class)->index($source))->toThrow(UnsafeArchive::class);
});

test('a zip with no images says the comic has no pages', function () {
    $source = cbzSource(['notes.txt' => 'hello', '__MACOSX/._1.jpg' => $this->png, 'dir/' => '']);

    expect(fn () => app(CbzExtractor::class)->index($source))->toThrow(UnsafeArchive::class, 'This comic has no pages.');
});

test('a corrupt zip is refused', function () {
    $source = storeCbz('this is not a zip file at all');

    expect(fn () => app(CbzExtractor::class)->index($source))->toThrow(UnsafeArchive::class, 'damaged');
});

test('a source with no stored file is refused', function () {
    $source = ContentSource::factory()->publicDomain()->create(['format' => 'cbz']);

    expect(fn () => app(CbzExtractor::class)->index($source))->toThrow(UnsafeArchive::class);
});

test('the index is cached so the zip is read once', function () {
    $source = ContentSource::factory()->cbz(3)->create();
    $extractor = app(CbzExtractor::class);

    $first = $extractor->index($source);
    Storage::disk(ContentSource::disk())->delete($source->storage_path);

    expect($extractor->index($source)->count())->toBe(3)->and($first->count())->toBe(3);
});

test('a replaced file is not served from a stale index', function () {
    $source = ContentSource::factory()->cbz(3)->create();
    app(CbzExtractor::class)->index($source);

    $other = ContentSource::factory()->cbz(5)->create();
    $source->update(['storage_path' => $other->storage_path, 'checksum' => $other->checksum]);

    expect(app(CbzExtractor::class)->index($source->fresh())->count())->toBe(5);
});
