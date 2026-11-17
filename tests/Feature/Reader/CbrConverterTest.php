<?php

use App\Enums\ContentFormat;
use App\Enums\ContentStatus;
use App\Jobs\ConvertCbrToCbz;
use App\Models\ContentSource;
use App\Reader\Cbz\CbrConverter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;

/*
 * CBR to CBZ conversion (P9-R11): the archive is listed and refused before extraction, extracted by `unar` or `bsdtar` (faked here), checked
 * again as a tree, and rebuilt as a CBZ. Any failure leaves the CBR untouched and the source failed.
 */

beforeEach(function () {
    Storage::fake(ContentSource::disk());
    $this->bin = sys_get_temp_dir().'/cbr-bin-'.uniqid();
    mkdir($this->bin);
    foreach (['unar', 'lsar', 'bsdtar'] as $name) {
        file_put_contents("{$this->bin}/{$name}", "#!/bin/sh\n");
        chmod("{$this->bin}/{$name}", 0755);
    }
    config([
        'bookflix.reader.cbr.unar_path' => "{$this->bin}/unar",
        'bookflix.reader.cbr.bsdtar_path' => "{$this->bin}/bsdtar",
    ]);
    $this->log = new ArrayObject(['ran' => [], 'dirs' => []]);
    $GLOBALS['cbrLog'] = $this->log;
});

afterEach(function () {
    File::deleteDirectory($this->bin);
});

function cbrSource(): ContentSource
{
    $path = 'content/factory/old.cbr';
    Storage::disk(ContentSource::disk())->put($path, 'Rar!fake');

    return ContentSource::factory()->create([
        'format' => ContentFormat::Cbr,
        'storage_path' => $path,
        'byte_size' => 8,
        'status' => ContentStatus::Pending,
    ]);
}

function pngBytes(int $n): string
{
    $image = imagecreatetruecolor($n, 1);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function lsarJson(array $entries): string
{
    return json_encode(['lsarContents' => $entries]);
}

function lsarEntry(string $name, int $size = 10, array $extra = []): array
{
    return ['XADFileName' => $name, 'XADFileSize' => $size, ...$extra];
}

function bsdtarLine(string $name, int $size = 10, string $mode = '-rw-r--r--'): string
{
    return "{$mode}  0 user group {$size} Jan  1 12:00 {$name}";
}

/**
 * Fake the tools. `$files` (relative path => bytes, or a callable receiving the output dir) is written when the extract command runs.
 *
 * @param  array<string, string>|callable  $files
 */
function fakeTools(string $listing, array|callable $files = [], int $listExit = 0, int $extractExit = 0, bool $timeout = false): void
{
    $log = $GLOBALS['cbrLog'];

    Process::fake(function (PendingProcess $process) use ($log, $listing, $files, $listExit, $extractExit, $timeout) {
        $command = $process->command;
        $log['ran'] = [...$log['ran'], $command];
        $isList = in_array('-j', $command, true) || in_array('-tvf', $command, true);

        if ($isList) {
            return Process::result($listing, '', $listExit);
        }

        $flag = array_search('-o', $command, true) ?: array_search('-C', $command, true);
        $dir = $command[$flag + 1];
        $log['dirs'] = [...$log['dirs'], $dir];

        if ($timeout) {
            throw new ProcessTimedOutException(
                new SymfonyTimeout(new SymfonyProcess(['x']), SymfonyTimeout::TYPE_GENERAL),
                Process::result('', '', 1),
            );
        }

        if (is_callable($files)) {
            $files($dir);
        } else {
            foreach ($files as $relative => $bytes) {
                @mkdir(dirname("{$dir}/{$relative}"), 0777, true);
                file_put_contents("{$dir}/{$relative}", $bytes);
            }
        }

        return Process::result('', 'boom', $extractExit);
    });
}

function extractRan(): bool
{
    return collect($GLOBALS['cbrLog']['ran'])->contains(fn ($c) => in_array('-D', $c, true) || in_array('-xf', $c, true));
}

it('converts a cbr and swaps it for a cbz with its size and checksum', function () {
    $source = cbrSource();
    fakeTools(lsarJson([lsarEntry('b/10.png'), lsarEntry('b/9.png'), lsarEntry('b', 0, ['XADIsDirectory' => 1])]), [
        'b/10.png' => pngBytes(10),
        'b/9.png' => pngBytes(9),
        'notes.txt' => 'hello',
        '__MACOSX/b/._9.png' => pngBytes(3),
        '.hidden.png' => pngBytes(4),
        'fake.png' => 'not an image',
    ]);

    $converted = app(CbrConverter::class)->convert($source);

    expect($converted->format)->toBe(ContentFormat::Cbz)
        ->and($converted->status)->toBe(ContentStatus::Ready)
        ->and($converted->failure_reason)->toBeNull()
        ->and($converted->mime_type)->toBe('application/vnd.comicbook+zip')
        ->and($converted->storage_path)->toStartWith('content/factory/')->toEndWith('.cbz');

    $disk = Storage::disk(ContentSource::disk());
    expect($disk->exists('content/factory/old.cbr'))->toBeFalse()
        ->and($converted->byte_size)->toBe($disk->size($converted->storage_path))
        ->and($converted->checksum)->toBe(hash('sha256', $disk->get($converted->storage_path)));

    $zip = new ZipArchive;
    $zip->open($disk->path($converted->storage_path));
    expect($zip->numFiles)->toBe(2)
        ->and($zip->getNameIndex(0))->toBe('page-0001.png')
        ->and($zip->getFromName('page-0001.png'))->toBe(pngBytes(9))
        ->and($zip->getFromName('page-0002.png'))->toBe(pngBytes(10));

    foreach ($this->log['dirs'] as $dir) {
        expect(is_dir($dir))->toBeFalse();
    }
});

it('lists with lsar and extracts with unar using array commands', function () {
    $source = cbrSource();
    fakeTools(lsarJson([lsarEntry('1.png')]), ['1.png' => pngBytes(1)]);

    $converter = app(CbrConverter::class);
    expect($converter->tool())->toBe('unar');
    $converter->convert($source);

    $file = Storage::disk(ContentSource::disk())->path('content/factory/old.cbr');
    expect($this->log['ran'][0])->toBe(["{$this->bin}/lsar", '-j', $file])
        ->and($this->log['ran'][1])->toBe(["{$this->bin}/unar", '-q', '-f', '-D', '-o', $this->log['dirs'][0], $file])
        ->and($this->log['dirs'][0])->toContain('app/private/tmp/cbr-');
});

it('lists and extracts with bsdtar when unar is missing', function () {
    config(['bookflix.reader.cbr.unar_path' => '/nonexistent/unar']);
    $source = cbrSource();
    fakeTools(bsdtarLine('dir', 0, 'drwxr-xr-x')."\n".bsdtarLine('dir/my page 1.png'), ['dir/my page 1.png' => pngBytes(1)]);

    $converter = app(CbrConverter::class);
    expect($converter->available())->toBeTrue()->and($converter->tool())->toBe('bsdtar');
    $converted = $converter->convert($source);

    $file = Storage::disk(ContentSource::disk())->path('content/factory/old.cbr');
    expect($converted->format)->toBe(ContentFormat::Cbz)
        ->and($this->log['ran'][0])->toBe(["{$this->bin}/bsdtar", '-tvf', $file])
        ->and($this->log['ran'][1])->toBe(["{$this->bin}/bsdtar", '-xf', $file, '-C', $this->log['dirs'][0], '--no-same-owner', '--no-same-permissions']);
});

it('is unavailable without either tool and says so', function () {
    config(['bookflix.reader.cbr.unar_path' => '/nonexistent/unar', 'bookflix.reader.cbr.bsdtar_path' => '/nonexistent/bsdtar']);
    $source = cbrSource();
    Process::fake();

    $converter = app(CbrConverter::class);
    expect($converter->available())->toBeFalse()->and($converter->tool())->toBeNull()
        ->and(CbrConverter::UNAVAILABLE_MESSAGE)->toBe('This server reads CBZ, not CBR. Convert the file to CBZ and upload it again.');

    $converter->convert($source);

    expect($source->fresh()->status)->toBe(ContentStatus::Failed)
        ->and($source->fresh()->failure_reason)->toBe(CbrConverter::UNAVAILABLE_MESSAGE);
    Process::assertNothingRan();
});

it('fails and keeps the original when the tool exits non-zero', function () {
    $source = cbrSource();
    fakeTools(lsarJson([lsarEntry('1.png')]), ['1.png' => pngBytes(1)], extractExit: 2);

    app(CbrConverter::class)->convert($source);

    $source->refresh();
    expect($source->status)->toBe(ContentStatus::Failed)
        ->and($source->failure_reason)->not->toBeEmpty()
        ->and($source->format)->toBe(ContentFormat::Cbr)
        ->and($source->storage_path)->toBe('content/factory/old.cbr')
        ->and(Storage::disk(ContentSource::disk())->exists('content/factory/old.cbr'))->toBeTrue()
        ->and(is_dir($this->log['dirs'][0]))->toBeFalse();
});

it('fails and keeps the original when the tool times out', function () {
    $source = cbrSource();
    fakeTools(lsarJson([lsarEntry('1.png')]), timeout: true);

    app(CbrConverter::class)->convert($source);

    $source->refresh();
    expect($source->status)->toBe(ContentStatus::Failed)
        ->and($source->failure_reason)->toContain('too long')
        ->and($source->format)->toBe(ContentFormat::Cbr)
        ->and(Storage::disk(ContentSource::disk())->exists('content/factory/old.cbr'))->toBeTrue()
        ->and(is_dir($this->log['dirs'][0]))->toBeFalse();
});

it('refuses a hostile listing before anything is extracted', function (string $listing) {
    $source = cbrSource();
    fakeTools($listing, ['1.png' => pngBytes(1)]);

    app(CbrConverter::class)->convert($source);

    expect($source->fresh()->status)->toBe(ContentStatus::Failed)
        ->and($source->fresh()->format)->toBe(ContentFormat::Cbr)
        ->and(extractRan())->toBeFalse()
        ->and(Storage::disk(ContentSource::disk())->exists('content/factory/old.cbr'))->toBeTrue();
})->with([
    'parent segment' => fn () => lsarJson([lsarEntry('../evil.jpg')]),
    'absolute' => fn () => lsarJson([lsarEntry('/etc/evil.jpg')]),
    'backslash' => fn () => lsarJson([lsarEntry('a\\evil.jpg')]),
    'drive letter' => fn () => lsarJson([lsarEntry('C:evil.jpg')]),
    'link flag' => fn () => lsarJson([lsarEntry('page.jpg', 0, ['XADIsLink' => 1])]),
    'too many entries' => fn () => lsarJson(array_map(fn ($i) => lsarEntry("p{$i}.jpg"), range(1, 2001))),
    'oversize total' => fn () => lsarJson([lsarEntry('a.jpg', 600 * 1048576), lsarEntry('b.jpg', 600 * 1048576)]),
    'not json' => fn () => 'garbage',
]);

it('refuses a hostile bsdtar listing before anything is extracted', function (string $listing) {
    config(['bookflix.reader.cbr.unar_path' => '/nonexistent/unar']);
    $source = cbrSource();
    fakeTools($listing);

    app(CbrConverter::class)->convert($source);

    expect($source->fresh()->status)->toBe(ContentStatus::Failed)->and(extractRan())->toBeFalse();
})->with([
    'parent segment' => fn () => bsdtarLine('a/../../evil.jpg'),
    'absolute' => fn () => bsdtarLine('/evil.jpg'),
    'symlink' => fn () => bsdtarLine('page.jpg -> /etc/passwd', 0, 'lrwxrwxrwx'),
    'oversize' => fn () => bsdtarLine('a.jpg', 2000 * 1048576),
    'unreadable line' => fn () => 'something odd',
]);

it('refuses an extracted symlink', function () {
    $source = cbrSource();
    fakeTools(lsarJson([lsarEntry('1.png')]), function (string $dir) {
        file_put_contents("{$dir}/1.png", pngBytes(1));
        symlink('/etc/passwd', "{$dir}/2.png");
    });

    app(CbrConverter::class)->convert($source);

    expect($source->fresh()->status)->toBe(ContentStatus::Failed)
        ->and($source->fresh()->failure_reason)->toContain('link')
        ->and(Storage::disk(ContentSource::disk())->exists('content/factory/old.cbr'))->toBeTrue()
        ->and(is_dir($this->log['dirs'][0]))->toBeFalse();
});

it('fails an archive with no images', function () {
    $source = cbrSource();
    fakeTools(lsarJson([lsarEntry('readme.txt')]), ['readme.txt' => 'text', 'x.jpg' => 'not really a jpg']);

    app(CbrConverter::class)->convert($source);

    expect($source->fresh()->status)->toBe(ContentStatus::Failed)
        ->and($source->fresh()->failure_reason)->toContain('no pages')
        ->and($source->fresh()->format)->toBe(ContentFormat::Cbr);
});

it('runs the job once per source and only for a cbr with a file', function () {
    expect(new ConvertCbrToCbz(5))->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new ConvertCbrToCbz(5))->uniqueId())->toBe('5')
        ->and((new ConvertCbrToCbz(5))->queue)->toBe('ingestion')
        ->and((new ConvertCbrToCbz(5))->tries)->toBe(1);

    Process::fake();
    $cbz = ContentSource::factory()->cbz()->create();
    $before = $cbz->fresh()->only(['storage_path', 'checksum', 'status']);

    (new ConvertCbrToCbz($cbz->id))->handle(app(CbrConverter::class));
    (new ConvertCbrToCbz(999999))->handle(app(CbrConverter::class));

    expect($cbz->fresh()->only(['storage_path', 'checksum', 'status']))->toBe($before);
    Process::assertNothingRan();

    $cbr = cbrSource();
    fakeTools(lsarJson([lsarEntry('1.png')]), ['1.png' => pngBytes(1)]);
    (new ConvertCbrToCbz($cbr->id))->handle(app(CbrConverter::class));
    expect($cbr->fresh()->format)->toBe(ContentFormat::Cbz);

    $path = $cbr->fresh()->storage_path;
    (new ConvertCbrToCbz($cbr->id))->handle(app(CbrConverter::class));
    expect($cbr->fresh()->storage_path)->toBe($path);
});
