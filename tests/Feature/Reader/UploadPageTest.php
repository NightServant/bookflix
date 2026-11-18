<?php

use App\Enums\AccessType;
use App\Models\ContentSource;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The upload screen (`library.upload`): who may open it, the story picker (the mature gate holds), a file through Livewire's temporary upload into
 * UploadContentSource, the refusals it shows under the right field, Your files with the quota, and deleting only your own.
 */

afterEach(fn () => Livewire::flushState());

beforeEach(function () {
    Storage::fake(ContentSource::disk());

    $this->reader = User::factory()->create();
    $this->media = Media::factory()->novel()->create(['title' => 'The Open Book']);
    $this->epub = fn (string $name = 'book.epub') => UploadedFile::fake()->createWithContent($name, tinyEpub());
    $this->upload = fn () => Livewire::actingAs($this->reader)->test('pages::library.upload');
});

describe('access', function () {
    test('a reader gets the page', function () {
        $this->actingAs($this->reader)->get(route('library.upload'))->assertOk()->assertSee('Add your own file')->assertSee('Your files');
    });

    test('a guest is sent to sign in', function () {
        $this->get(route('library.upload'))->assertRedirect(route('login'));
    });

    test('an admin is sent to the admin side', function () {
        $this->actingAs(User::factory()->admin()->create())->get(route('library.upload'))->assertRedirect(route('admin.index'));
    });

    test('the library page links to it', function () {
        $this->actingAs($this->reader)->get(route('library.index'))->assertSee(route('library.upload'), false);
    });
});

describe('the story', function () {
    test('?story= preselects a visible story', function () {
        $this->actingAs($this->reader)->get(route('library.upload', ['story' => $this->media->slug]))->assertOk();

        Livewire::withQueryParams(['story' => $this->media->slug])->actingAs($this->reader)->test('pages::library.upload')->assertSet('story', $this->media->slug);
    });

    test('an unknown or hidden story in the link is ignored', function () {
        $mature = matureStory();

        Livewire::withQueryParams(['story' => $mature->slug])->actingAs($this->reader)->test('pages::library.upload')->assertSet('story', '');
        Livewire::withQueryParams(['story' => 'nope'])->actingAs($this->reader)->test('pages::library.upload')->assertSet('story', '');
    });

    test('the picker lists visible stories only', function () {
        $mature = matureStory('Hidden Tale');

        $stories = ($this->upload)()->instance()->stories();

        expect($stories)->toHaveKey($this->media->slug)->not->toHaveKey($mature->slug);
    });

    test('a story behind the mature gate cannot be chosen', function () {
        $mature = matureStory();

        ($this->upload)()->set('story', $mature->slug)->set('file', ($this->epub)())->set('own', true)->call('save')
            ->assertHasErrors('media')->assertSee('Choose a story from the catalog.');

        expect(ContentSource::count())->toBe(0);
    });
});

describe('uploading', function () {
    test('a valid EPUB becomes the reader\'s own source for the chosen story, and is listed', function () {
        ($this->upload)()->set('story', $this->media->slug)->set('file', ($this->epub)('mine.epub'))->set('own', true)->call('save')
            ->assertHasNoErrors()->assertSee('Added. Open it from the story or from Your files.')->assertSet('story', '')->assertSet('own', false)->assertSet('file', null)
            ->assertSee('The Open Book')->assertSee('EPUB');

        $source = ContentSource::sole();
        expect($source->media_id)->toBe($this->media->id)->and($source->owner_user_id)->toBe($this->reader->id)->and($source->access_type)->toBe(AccessType::UserOwned);
    });

    test('the ownership box is required', function () {
        ($this->upload)()->set('story', $this->media->slug)->set('file', ($this->epub)())->call('save')
            ->assertHasErrors(['own' => 'accepted'])->assertSee('Confirm that this is your own copy.');

        expect(ContentSource::count())->toBe(0);
    });

    test('a story must be chosen', function () {
        ($this->upload)()->set('file', ($this->epub)())->set('own', true)->call('save')
            ->assertHasErrors(['story' => 'required'])->assertSee('Choose the story this file belongs to.');
    });

    test('a file must be chosen', function () {
        ($this->upload)()->set('story', $this->media->slug)->set('own', true)->call('save')->assertHasErrors(['file' => 'required']);
    });

    test('a refused file shows the action\'s message under the file field', function () {
        ($this->upload)()->set('story', $this->media->slug)->set('file', UploadedFile::fake()->createWithContent('book.epub', '<html>not a book</html>'))->set('own', true)->call('save')
            ->assertHasErrors('file')->assertSee('This file is not an EPUB, PDF, CBZ or CBR.')->assertSeeHtml('role="alert"');

        expect(ContentSource::count())->toBe(0);
    });

    test('the chosen file shows its name, type from the extension and size, and can be removed', function () {
        ($this->upload)()->set('file', ($this->epub)('alice.epub'))
            ->assertSee('alice.epub')->assertSeeHtml('data-chosen-file')->assertSee('EPUB ·')
            ->call('removeFile')->assertSet('file', null)->assertDontSeeHtml('data-chosen-file');
    });
});

describe('what it says about this server', function () {
    test('the caps line comes from config and mentions CBR only when it can be unpacked', function (string $tool, bool $available) {
        config()->set('bookflix.reader.cbr.unar_path', $tool);
        config()->set('bookflix.reader.cbr.bsdtar_path', $tool);

        $html = ($this->upload)()->html();

        expect($html)->toContain('EPUB up to 100 MB')->toContain('PDF up to 200 MB');

        if ($available) {
            expect($html)->toContain('CBZ or CBR up to 500 MB')->toContain('.cbr');
        } else {
            expect($html)->toContain('CBZ up to 500 MB')->not->toContain('CBR')->not->toContain('.cbr');
        }
    })->with([
        'a tool is installed' => ['/bin/sh', true],
        'no tool is installed' => ['/nonexistent/unpacker', false],
    ]);
});

describe('your files', function () {
    test('the quota numbers and the rows render', function () {
        ContentSource::factory()->for($this->media)->create(['access_type' => AccessType::UserOwned, 'owner_user_id' => $this->reader->id, 'byte_size' => 38 * 1048576]);

        ($this->upload)()->assertSee('38 MB of 2 GB used')->assertSee('The Open Book')->assertSeeHtml('aria-valuemax="100"')->assertSeeHtml('aria-valuenow="2"')
            ->assertSee('Up to 10 uploads an hour.');
    });

    test('another reader\'s files are not listed, and a Read link opens the reader on that source', function () {
        $mine = ContentSource::factory()->for($this->media)->ready()->create(['access_type' => AccessType::UserOwned, 'owner_user_id' => $this->reader->id]);
        $other = Media::factory()->novel()->create(['title' => 'Somebody Else']);
        ContentSource::factory()->for($other)->create(['access_type' => AccessType::UserOwned, 'owner_user_id' => User::factory()->create()->id]);

        ($this->upload)()->assertSeeHtml('aria-label="Read The Open Book"')->assertDontSeeHtml('aria-label="Read Somebody Else"')->assertDontSeeHtml('aria-label="Delete Somebody Else"')->assertSeeHtml(e(route('reader.show', ['slug' => $this->media->slug, 'source' => $mine->id])));
    });

    test('deleting removes the source and its file', function () {
        $source = ContentSource::factory()->for($this->media)->create(['access_type' => AccessType::UserOwned, 'owner_user_id' => $this->reader->id, 'storage_path' => 'content/1/x.epub']);
        Storage::disk(ContentSource::disk())->put($source->storage_path, 'x');

        ($this->upload)()->call('delete', $source->id)->assertDontSeeHtml('aria-label="Delete The Open Book"');

        expect(ContentSource::find($source->id))->toBeNull();
        Storage::disk(ContentSource::disk())->assertMissing($source->storage_path);
    });

    test('a file of a story deleted since is listed as Story removed, with Delete and no Read link, and can be deleted', function () {
        $source = ContentSource::factory()->for($this->media)->ready()->create(['access_type' => AccessType::UserOwned, 'owner_user_id' => $this->reader->id, 'storage_path' => 'content/1/x.epub']);
        Storage::disk(ContentSource::disk())->put($source->storage_path, 'x');
        $this->media->delete();

        $page = ($this->upload)()->assertOk()->assertSee('Story removed')->assertSeeHtml('aria-label="Delete Story removed"')->assertDontSeeHtml('aria-label="Read Story removed"');

        expect($page->html())->not->toContain('/read/');

        $page->call('delete', $source->id);

        expect(ContentSource::find($source->id))->toBeNull();
        Storage::disk(ContentSource::disk())->assertMissing($source->storage_path);
    });

    test('only the owner can delete a file', function () {
        $source = ContentSource::factory()->for($this->media)->create(['access_type' => AccessType::UserOwned, 'owner_user_id' => User::factory()->create()->id]);
        $public = ContentSource::factory()->for($this->media)->publicDomain()->verified()->ready()->create();

        ($this->upload)()->call('delete', $source->id)->assertForbidden();
        ($this->upload)()->call('delete', $public->id)->assertForbidden();

        expect(ContentSource::find($source->id))->not->toBeNull()->and(ContentSource::find($public->id))->not->toBeNull();
    });
});

test('Livewire\'s temporary uploads never use a public disk', function () {
    $name = config('livewire.temporary_file_upload.disk');
    $disk = config("filesystems.disks.{$name}");

    expect($name)->toBe('local')
        ->and($name)->not->toBe('public')
        ->and($disk['visibility'] ?? 'private')->not->toBe('public')
        ->and($disk['root'] ?? '')->not->toContain('public')
        ->and(config('livewire.temporary_file_upload.rules'))->toContain('max:'.(500 * 1024))
        ->and(config('livewire.temporary_file_upload.max_upload_time'))->toBe(30);
});
