<?php

use App\Reader\Archives\EpubInspector;
use App\Reader\Archives\UnsafeArchive;
use App\Reader\Archives\ZipGuard;

/*
 * The archive guard (P9-R10): hostile zips are built here entry by entry and must all be refused before anything is read from them.
 */

/**
 * @param  array<string, string>  $files
 * @param  (callable(ZipArchive): void)|null  $after
 */
function makeZip(array $files, ?callable $after = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'zg');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
    }

    if ($after !== null) {
        $after($zip);
    }

    $zip->close();

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/zg*') ?: [] as $file) {
        @unlink($file);
    }
});

test('a valid zip passes and lists its entries', function () {
    $inventory = (new ZipGuard)->inspect(makeZip(['a.jpg' => 'one', 'dir/b.jpg' => 'three']));

    expect($inventory->count())->toBe(2)
        ->and($inventory->first()->name)->toBe('a.jpg')
        ->and($inventory->find('dir/b.jpg')->size)->toBe(5);
});

test('unsafe entry names are refused', function (string $name) {
    $path = makeZip([$name => 'x']);

    expect(fn () => (new ZipGuard)->inspect($path))->toThrow(UnsafeArchive::class, 'This file is not a safe archive');
})->with(['parent segment' => '../../evil.jpg', 'inner parent' => 'a/../../evil.jpg', 'absolute' => '/abs.jpg', 'backslash' => 'a\\b.jpg', 'drive letter' => 'C:x.jpg']);

test('a symlink entry is refused', function () {
    $path = makeZip(['link.jpg' => 'target.jpg'], function (ZipArchive $zip) {
        $zip->setExternalAttributesName('link.jpg', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    });

    expect(fn () => (new ZipGuard)->inspect($path))->toThrow(UnsafeArchive::class, 'link');
});

test('too many entries are refused', function () {
    config(['bookflix.reader.archive.max_entries' => 3]);

    expect(fn () => (new ZipGuard)->inspect(makeZip(['1' => 'a', '2' => 'a', '3' => 'a', '4' => 'a'])))->toThrow(UnsafeArchive::class, 'too many');
    expect((new ZipGuard)->inspect(makeZip(['1' => 'a', '2' => 'a', '3' => 'a']))->count())->toBe(3);
});

test('an archive that unpacks to too much is refused', function () {
    config(['bookflix.reader.archive.max_uncompressed_mb' => 1]);

    $path = makeZip(['big.bin' => random_bytes(1048577)]);

    expect(fn () => (new ZipGuard)->inspect($path))->toThrow(UnsafeArchive::class, 'too much');
});

test('a very high compression ratio is refused', function () {
    $path = makeZip(['bomb.txt' => str_repeat("\0", 20 * 1048576)]);

    expect(fn () => (new ZipGuard)->inspect($path))->toThrow(UnsafeArchive::class, 'larger than it is');
});

test('a single entry with a high ratio hides in a large honest archive no longer', function () {
    $path = makeZip(['honest.bin' => random_bytes(3 * 1048576), 'bomb.txt' => str_repeat("\0", 30 * 1048576)]);

    expect(fn () => (new ZipGuard)->inspect($path))->toThrow(UnsafeArchive::class, 'larger than it is');
});

test('an empty zip is refused', function () {
    $path = tempnam(sys_get_temp_dir(), 'zg');
    file_put_contents($path, "PK\x05\x06".str_repeat("\0", 18));

    expect(fn () => (new ZipGuard)->inspect($path))->toThrow(UnsafeArchive::class, 'empty');
});

test('a file that is not a zip is refused as damaged', function () {
    $path = tempnam(sys_get_temp_dir(), 'zg');
    file_put_contents($path, '<html>not a zip</html>');

    expect(fn () => (new ZipGuard)->inspect($path))->toThrow(UnsafeArchive::class, 'damaged');
});

test('reading an entry returns its bytes within the cap', function () {
    $zip = new ZipArchive;
    $zip->open(makeZip(['a.txt' => 'hello']), ZipArchive::RDONLY);

    expect((new ZipGuard)->readEntry($zip, 0, 100))->toBe('hello');
    expect(fn () => (new ZipGuard)->readEntry($zip, 0, 3))->toThrow(UnsafeArchive::class);
});

test('an entry that lies about its size is caught while reading', function () {
    $path = makeZip(['a.txt' => str_repeat('x', 400)], fn (ZipArchive $zip) => $zip->setCompressionName('a.txt', ZipArchive::CM_STORE));

    // Rewrite the declared uncompressed size in the central directory (offset 24 of its header) to 10 bytes.
    $bytes = file_get_contents($path);
    $header = strpos($bytes, "PK\x01\x02");
    $bytes = substr_replace($bytes, pack('V', 10), $header + 24, 4);
    file_put_contents($path, $bytes);

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::RDONLY);

    expect(fn () => (new ZipGuard)->readEntry($zip, 0, 1000))->toThrow(UnsafeArchive::class);
});

test('an epub needs a stored mimetype first and a container', function () {
    $good = makeZip(['mimetype' => 'application/epub+zip', 'META-INF/container.xml' => '<container/>'], fn (ZipArchive $zip) => $zip->setCompressionName('mimetype', ZipArchive::CM_STORE));
    (new EpubInspector(new ZipGuard))->check($good);

    $notFirst = makeZip(['META-INF/container.xml' => '<container/>', 'mimetype' => 'application/epub+zip']);
    $wrongText = makeZip(['mimetype' => 'text/plain', 'META-INF/container.xml' => '<container/>'], fn (ZipArchive $zip) => $zip->setCompressionName('mimetype', ZipArchive::CM_STORE));
    $noContainer = makeZip(['mimetype' => 'application/epub+zip'], fn (ZipArchive $zip) => $zip->setCompressionName('mimetype', ZipArchive::CM_STORE));
    $deflated = makeZip(['mimetype' => str_repeat('application/epub+zip', 1), 'META-INF/container.xml' => '<container/>'], fn (ZipArchive $zip) => $zip->setCompressionName('mimetype', ZipArchive::CM_DEFLATE));

    foreach ([$notFirst, $wrongText, $noContainer] as $path) {
        expect(fn () => (new EpubInspector(new ZipGuard))->check($path))->toThrow(UnsafeArchive::class, 'not a valid EPUB');
    }

    // A 20-byte entry may be stored by libzip even when asked to deflate, so only check the case when it really was deflated.
    $zip = new ZipArchive;
    $zip->open($deflated, ZipArchive::RDONLY);

    if ($zip->statIndex(0)['comp_method'] === ZipArchive::CM_DEFLATE) {
        expect(fn () => (new EpubInspector(new ZipGuard))->check($deflated))->toThrow(UnsafeArchive::class, 'not a valid EPUB');
    }
});
