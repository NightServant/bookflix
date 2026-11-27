<?php

use App\Actions\Reader\DeleteContentSource;
use App\Enums\AccessType;
use App\Enums\ContentFormat;
use App\Enums\ContentStatus;
use App\Jobs\ConvertCbrToCbz;
use App\Models\ContentSource;
use App\Models\Media;
use App\Models\ReadingProgress;
use App\Models\User;
use App\Reader\ContentUrl;
use App\Reader\UploadContentSource;
use Database\Factories\ContentSourceFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * A reader's own upload (P9-R12): the type is decided from the bytes alone, the caps and quota hold, and the file lands under a server-made name.
 */

beforeEach(function () {
    Storage::fake(ContentSource::disk());
    Queue::fake();
    $this->user = User::factory()->create();
    $this->media = Media::factory()->create();
});

function uploadBook(User $user, Media $media, UploadedFile $file): ContentSource
{
    return app(UploadContentSource::class)->handle($user, $media, $file);
}

function upRefusal(callable $attempt): ValidationException
{
    try {
        $attempt();
    } catch (ValidationException $e) {
        return $e;
    }

    throw new RuntimeException('The upload was not refused.');
}

function upFileOf(string $body, string $name = 'book.bin'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $body);
}

function upZipOf(array $entries, bool $mimetypeStored = true): string
{
    $temp = (string) tempnam(sys_get_temp_dir(), 'up');
    $zip = new ZipArchive;
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $body) {
        $zip->addFromString($name, $body);

        if ($name === 'mimetype' && $mimetypeStored) {
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
    }

    $zip->close();
    $bytes = (string) file_get_contents($temp);
    unlink($temp);

    return $bytes;
}

function upEpubBytes(): string
{
    return upZipOf([
        'mimetype' => 'application/epub+zip',
        'META-INF/container.xml' => '<container/>',
        'OEBPS/ch1.xhtml' => '<html/>',
    ]);
}

function upPdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
}

function upCbzBytes(): string
{
    return upZipOf(['01.png' => ContentSourceFactory::png(1), '02.png' => ContentSourceFactory::png(2)]);
}

function upRarBytes(): string
{
    return "Rar!\x1A\x07\x00".str_repeat('x', 40);
}

test('a valid EPUB is accepted and stored ready', function () {
    $source = uploadBook($this->user, $this->media, upFileOf(upEpubBytes(), 'My Book.epub'));

    expect($source->format)->toBe(ContentFormat::Epub)
        ->and($source->access_type)->toBe(AccessType::UserOwned)
        ->and($source->owner_user_id)->toBe($this->user->id)
        ->and($source->media_id)->toBe($this->media->id)
        ->and($source->license)->toBe('user-provided')
        ->and($source->verified_at)->not->toBeNull()
        ->and($source->verified_by)->toBeNull()
        ->and($source->status)->toBe(ContentStatus::Ready)
        ->and($source->mime_type)->toBe('application/epub+zip')
        ->and($source->original_name)->toBe('My Book.epub')
        ->and($source->byte_size)->toBe(strlen(upEpubBytes()))
        ->and($source->checksum)->toBe(hash('sha256', upEpubBytes()))
        ->and($source->storage_path)->toStartWith("content/{$this->user->id}/")->toEndWith('.epub');
    Storage::disk(ContentSource::disk())->assertExists($source->storage_path);
});

test('a valid PDF and a valid CBZ are accepted', function () {
    $pdf = uploadBook($this->user, $this->media, upFileOf(upPdfBytes(), 'x.dat'));
    $cbz = uploadBook($this->user, $this->media, upFileOf(upCbzBytes(), 'x.dat'));

    expect($pdf->format)->toBe(ContentFormat::Pdf)->and($pdf->storage_path)->toEndWith('.pdf')
        ->and($cbz->format)->toBe(ContentFormat::Cbz)->and($cbz->storage_path)->toEndWith('.cbz')
        ->and($cbz->status)->toBe(ContentStatus::Ready);
    Queue::assertNothingPushed();
});

test('a CBR is stored pending and converted in the background when a tool is installed', function () {
    $tool = sys_get_temp_dir().'/up-unar-'.uniqid();
    file_put_contents($tool, "#!/bin/sh\n");
    chmod($tool, 0755);
    config(['bookflix.reader.cbr.unar_path' => $tool, 'bookflix.reader.cbr.bsdtar_path' => '/nonexistent/bsdtar']);

    $source = uploadBook($this->user, $this->media, upFileOf(upRarBytes(), 'comic.cbr'));
    File::delete($tool);

    expect($source->format)->toBe(ContentFormat::Cbr)->and($source->status)->toBe(ContentStatus::Pending)
        ->and($source->storage_path)->toEndWith('.cbr');
    Queue::assertPushed(ConvertCbrToCbz::class, fn (ConvertCbrToCbz $job) => $job->contentSourceId === $source->id);
});

test('a CBR is refused with the CBZ-only message when no tool is installed', function () {
    config(['bookflix.reader.cbr.unar_path' => '/nonexistent/unar', 'bookflix.reader.cbr.bsdtar_path' => '/nonexistent/bsdtar']);

    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf(upRarBytes(), 'comic.cbr')));

    expect($e->errors()['file'][0])->toStartWith('This server reads CBZ, not CBR');
    expect(ContentSource::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('files that are not a book are refused whatever their name says', function (string $name, string $body) {
    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf($body, $name)));

    expect($e->errors()['file'][0])->toBe('This file is not an EPUB, PDF, CBZ or CBR.');
    expect(ContentSource::count())->toBe(0);
})->with([
    'exe' => ['book.epub', "MZ\x90\x00\x03\x00\x00\x00 program"],
    'html' => ['book.pdf', '<html><script>alert(1)</script></html>'],
    'svg' => ['cover.cbz', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'pdf name without the magic' => ['real.pdf', "not a pdf at all\n"],
    'html disguised as epub' => ['book.epub', '<!doctype html><html><body>hi</body></html>'],
]);

test('a zip that is not an EPUB and has no images is refused', function () {
    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf(upZipOf(['notes.txt' => 'hello']), 'x.cbz')));

    expect($e->errors()['file'][0])->toBe('This file is not an EPUB, PDF, CBZ or CBR.');
});

test('an unsafe archive is refused with the guard message', function () {
    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf(upZipOf(['../evil.png' => ContentSourceFactory::png(1)]), 'x.cbz')));

    expect($e->errors()['file'][0])->toStartWith('This file is not a safe archive');
});

test('an archive with too many entries is refused with the guard message', function () {
    config(['bookflix.reader.archive.max_entries' => 5]);
    $pages = collect(range(1, 6))->mapWithKeys(fn (int $n) => [sprintf('%02d.png', $n) => ContentSourceFactory::png($n)])->all();

    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf(upZipOf($pages), 'x.cbz')));

    expect($e->errors()['file'][0])->toBe('This file is not a safe archive: it holds too many files.');
});

test('an archive whose pages unpack past the size cap is refused with the guard message', function () {
    config(['bookflix.reader.archive.max_uncompressed_mb' => 1]);

    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf(upZipOf(['01.png' => random_bytes(1048577)]), 'x.cbz')));

    expect($e->errors()['file'][0])->toBe('This file is not a safe archive: it unpacks to too much data.');
});

test('an archive with a symlink entry is refused with the guard message', function () {
    $temp = (string) tempnam(sys_get_temp_dir(), 'up');
    $zip = new ZipArchive;
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('01.png', ContentSourceFactory::png(1));
    $zip->addFromString('02.png', '01.png');
    $zip->setExternalAttributesName('02.png', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    $bytes = (string) file_get_contents($temp);
    unlink($temp);

    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf($bytes, 'x.cbz')));

    expect($e->errors()['file'][0])->toBe('This file is not a safe archive: it holds a link.');
});

test('an empty file is refused', function () {
    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf('', 'empty.pdf')));

    expect($e->errors()['file'][0])->toBe('This file is empty.');
});

test('a file over its format cap is refused', function () {
    config(['bookflix.reader.upload.pdf_mb' => 0.0001]);

    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf(upPdfBytes().str_repeat('0', 500), 'big.pdf')));

    expect($e->errors()['file'][0])->toStartWith('This file is larger than');
    expect(ContentSource::count())->toBe(0);
});

test('a CBR is capped by the CBZ limit', function () {
    config(['bookflix.reader.upload.cbz_mb' => 0.00001, 'bookflix.reader.cbr.unar_path' => '/nonexistent/unar', 'bookflix.reader.cbr.bsdtar_path' => '/nonexistent/bsdtar']);
    $tool = sys_get_temp_dir().'/up-unar-'.uniqid();
    file_put_contents($tool, "#!/bin/sh\n");
    chmod($tool, 0755);
    config(['bookflix.reader.cbr.unar_path' => $tool]);

    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf(upRarBytes(), 'c.cbr')));
    File::delete($tool);

    expect($e->errors()['file'][0])->toStartWith('This file is larger than');
});

test('the per-user quota holds', function () {
    config(['bookflix.reader.upload.quota_mb' => 0.0002]);
    ContentSource::factory()->userOwned($this->user)->ready()->create(['byte_size' => 150]);
    // Another reader's file and a public file do not count against this reader.
    ContentSource::factory()->userOwned(User::factory()->create())->ready()->create(['byte_size' => 5000]);

    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf(upPdfBytes(), 'p.pdf')));

    expect($e->errors()['file'][0])->toStartWith('This file would take you over your');
});

test('the stored path holds none of the client file name', function () {
    $source = uploadBook($this->user, $this->media, upFileOf(upPdfBytes(), '../../etc/passwd secret.pdf'));

    expect($source->storage_path)->toMatch('#^content/'.$this->user->id.'/[0-9A-Za-z]{26}\.pdf$#')
        ->and($source->storage_path)->not->toContain('passwd')->not->toContain('secret')
        ->and($source->original_name)->toBe('passwd secret.pdf');
});

test('the display name is reduced to a safe string', function () {
    $source = uploadBook($this->user, $this->media, upFileOf(upPdfBytes(), "a\x00b\x07c".str_repeat('z', 400).'.pdf'));

    expect(mb_strlen($source->original_name))->toBeLessThanOrEqual(255)->and($source->original_name)->toStartWith('abc');
});

test('only the owner can stream the upload', function () {
    $source = uploadBook($this->user, $this->media, upFileOf(upPdfBytes(), 'p.pdf'));
    $other = User::factory()->create();

    $this->actingAs($other)->get(ContentUrl::for($other, $source))->assertForbidden();
    $this->actingAs($this->user)->get(ContentUrl::for($this->user, $source))->assertOk();
});

test('media the reader may not see is refused', function () {
    $mature = matureStory();

    $e = upRefusal(fn () => uploadBook($this->user, $mature, upFileOf(upPdfBytes(), 'p.pdf')));
    expect($e->errors())->toHaveKey('media');

    $opted = User::factory()->showsMature()->create();
    expect(uploadBook($opted, $mature, upFileOf(upPdfBytes(), 'p.pdf')))->toBeInstanceOf(ContentSource::class);
});

test('a deleted media is refused', function () {
    $this->media->delete();

    expect(upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf(upPdfBytes(), 'p.pdf')))->errors())->toHaveKey('media');
});

test('an admin and a deactivated reader are refused', function () {
    $admin = User::factory()->admin()->create();
    $gone = User::factory()->deactivated()->create();

    expect(upRefusal(fn () => uploadBook($admin, $this->media, upFileOf(upPdfBytes(), 'p.pdf')))->errors())->toHaveKey('user')
        ->and(upRefusal(fn () => uploadBook($gone, $this->media, upFileOf(upPdfBytes(), 'p.pdf')))->errors())->toHaveKey('user');
});

test('readers who opted out of personalization can upload like anyone else', function () {
    $optedOut = User::factory()->create();
    $optedOut->profile()->updateOrCreate([], ['personalization_enabled' => false]);

    expect(uploadBook($optedOut, $this->media, upFileOf(upPdfBytes(), 'p.pdf')))->toBeInstanceOf(ContentSource::class)
        ->and(uploadBook($this->user, $this->media, upFileOf(upPdfBytes(), 'p.pdf')))->toBeInstanceOf(ContentSource::class);
});

test('the eleventh attempt in an hour is refused, refused attempts included', function () {
    RateLimiter::clear('reader-upload:'.$this->user->id);

    for ($i = 0; $i < 9; $i++) {
        uploadBook($this->user, $this->media, upFileOf(upPdfBytes(), 'p.pdf'));
    }

    upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf('', 'empty.pdf')));

    $e = upRefusal(fn () => uploadBook($this->user, $this->media, upFileOf(upPdfBytes(), 'p.pdf')));
    expect($e->errors()['file'][0])->toBe("You've added 10 files this hour. Try again later.");
});

test('a failed database write removes the stored file', function () {
    ContentSource::creating(fn () => throw new RuntimeException('db down'));

    try {
        uploadBook($this->user, $this->media, upFileOf(upPdfBytes(), 'p.pdf'));
    } catch (RuntimeException) {
    }

    expect(Storage::disk(ContentSource::disk())->allFiles())->toBe([]);
    ContentSource::flushEventListeners();
});

test('deleting the source deletes the file and keeps the reading place', function () {
    $source = uploadBook($this->user, $this->media, upFileOf(upPdfBytes(), 'p.pdf'));
    $progress = ReadingProgress::factory()->create(['user_id' => $this->user->id, 'media_id' => $this->media->id, 'content_source_id' => $source->id]);

    app(DeleteContentSource::class)->handle($this->user, $source);

    Storage::disk(ContentSource::disk())->assertMissing($source->storage_path);
    expect(ContentSource::find($source->id))->toBeNull()
        ->and($progress->fresh()->content_source_id)->toBeNull()
        ->and($progress->fresh()->percent)->toEqual($progress->percent);
});

test('only the owner can delete an upload', function () {
    $source = uploadBook($this->user, $this->media, upFileOf(upPdfBytes(), 'p.pdf'));
    $public = ContentSource::factory()->publicDomain()->verified()->ready()->create();

    expect(fn () => app(DeleteContentSource::class)->handle(User::factory()->create(), $source))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteContentSource::class)->handle($this->user, $public))->toThrow(AuthorizationException::class);
    expect(ContentSource::find($source->id))->not->toBeNull();
});

test('purging a deactivated account deletes its uploaded files', function () {
    $gone = User::factory()->create(['deactivated_at' => now()->subDays(31)]);
    $kept = User::factory()->create();
    $mine = ContentSource::factory()->userOwned($gone)->ready()->create();
    $theirs = ContentSource::factory()->userOwned($kept)->ready()->create();
    $public = ContentSource::factory()->publicDomain()->verified()->ready()->create();

    $this->artisan('bookflix:purge-deactivated-accounts')->assertSuccessful();

    $disk = Storage::disk(ContentSource::disk());
    $disk->assertMissing($mine->storage_path);
    $disk->assertExists($theirs->storage_path);
    $disk->assertExists($public->storage_path);
    expect(User::find($gone->id))->toBeNull()->and(ContentSource::find($mine->id))->toBeNull();
});
