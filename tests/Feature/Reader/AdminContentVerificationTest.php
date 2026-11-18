<?php

use App\Actions\Reader\UnverifyContentSource;
use App\Actions\Reader\VerifyContentSource;
use App\Models\ContentSource;
use App\Models\Media;
use App\Models\User;
use App\Reader\ContentAccessGate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The admin Reading files page and the verify actions behind it (P9-R5): a license is required, a reader's own upload is never listed or touched.
 */

beforeEach(function () {
    Storage::fake(ContentSource::disk());
});

test('only an admin opens the page', function () {
    $this->get(route('admin.content'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('admin.content'))->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.content'))->assertOk()->assertSee('Reading files');
});

test('verifying records the admin, the time, the license and the rights holder', function () {
    $this->freezeTime();
    $admin = User::factory()->admin()->create();
    $source = ContentSource::factory()->ready()->create();

    app(VerifyContentSource::class)->handle($source, $admin, "  Public domain, Project Gutenberg  \n", ' Jane Doe ');

    expect($source->fresh())
        ->verified_by->toBe($admin->id)
        ->verified_at->not->toBeNull()
        ->license->toBe('Public domain, Project Gutenberg')
        ->rights_holder->toBe('Jane Doe');
});

test('the license is required and at most 2000 characters, the rights holder is optional', function () {
    $admin = User::factory()->admin()->create();
    $source = ContentSource::factory()->create();

    expect(fn () => app(VerifyContentSource::class)->handle($source, $admin, '   ', null))->toThrow(ValidationException::class)
        ->and(fn () => app(VerifyContentSource::class)->handle($source, $admin, str_repeat('a', 2001), null))->toThrow(ValidationException::class)
        ->and($source->fresh()->verified_at)->toBeNull();

    app(VerifyContentSource::class)->handle($source, $admin, str_repeat('a', 2000), '');

    expect($source->fresh())->verified_at->not->toBeNull()->rights_holder->toBeNull();
});

test('a link out can be verified, a reader\'s own upload cannot', function () {
    $admin = User::factory()->admin()->create();
    $link = ContentSource::factory()->externalLink()->create();
    $owned = ContentSource::factory()->userOwned(User::factory()->create())->create(['verified_at' => null]);

    app(VerifyContentSource::class)->handle($link, $admin, 'Publisher permission', null);

    expect($link->fresh()->isVerified())->toBeTrue()
        ->and(fn () => app(VerifyContentSource::class)->handle($owned, $admin, 'x', null))->toThrow(ValidationException::class)
        ->and(fn () => app(UnverifyContentSource::class)->handle($owned))->toThrow(ValidationException::class)
        ->and($owned->fresh()->verified_at)->toBeNull();
});

test('verify through the page opens the file to readers, unverify closes it at once', function () {
    $admin = User::factory()->admin()->create();
    $reader = User::factory()->create();
    $source = ContentSource::factory()->ready()->create();
    $gate = app(ContentAccessGate::class);

    $this->actingAs($admin);
    $page = Livewire::test('pages::admin.content.index')
        ->call('edit', $source->id)
        ->assertSet('license', 'Public domain in the United States')
        ->set('license', 'Standard Ebooks, public domain')
        ->set('rightsHolder', 'Standard Ebooks')
        ->call('save')
        ->assertHasNoErrors();

    expect($gate->allows($reader, $source->fresh()))->toBeTrue()
        ->and($source->fresh()->rights_holder)->toBe('Standard Ebooks');

    $page->call('unverify', $source->id);

    expect($gate->allows($reader, $source->fresh()))->toBeFalse()
        ->and($source->fresh())->verified_by->toBeNull()->verified_at->toBeNull();
});

test('saving without a license shows an error and verifies nothing', function () {
    $source = ContentSource::factory()->create();

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test('pages::admin.content.index')
        ->call('edit', $source->id)
        ->set('license', '  ')
        ->call('save')
        ->assertHasErrors(['license' => 'required']);

    expect($source->fresh()->verified_at)->toBeNull();
});

test('a reader\'s own upload is never listed and its id answers 404 on every action', function () {
    $owned = ContentSource::factory()->userOwned(User::factory()->create())->ready()->create(['original_name' => 'secret.epub']);
    $owned->media->update(['title' => 'Private Upload Title']);

    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test('pages::admin.content.index')->assertDontSee('Private Upload Title')->assertSee('No reading files yet');

    $page->call('edit', $owned->id)->assertNotFound();
    Livewire::test('pages::admin.content.index')->set('verifying', $owned->id)->set('license', 'x')->call('save')->assertNotFound();
    Livewire::test('pages::admin.content.index')->call('unverify', $owned->id)->assertNotFound();

    expect($owned->fresh()->verified_by)->toBeNull();
});

test('the actions refuse a non admin', function () {
    $source = ContentSource::factory()->create();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $page = Livewire::test('pages::admin.content.index');

    $this->actingAs(User::factory()->create());
    $page->call('edit', $source->id)->assertForbidden();
    Livewire::test('pages::admin.content.index')->assertForbidden();

    expect($source->fresh()->verified_at)->toBeNull();
});

test('it lists the story, access, format, state and license, and filters by state', function () {
    $admin = User::factory()->admin()->create();
    $unverified = ContentSource::factory()->create(['license' => 'License of the unverified one']);
    $unverified->media->update(['title' => 'Unverified Tale']);
    $verified = ContentSource::factory()->licensed()->verified()->ready()->create();
    $verified->media->update(['title' => 'Verified Tale']);
    $failed = ContentSource::factory()->create(['status' => 'failed', 'failure_reason' => 'Not an EPUB']);
    $failed->media->update(['title' => 'Failed Tale']);

    $this->actingAs($admin);
    $page = Livewire::test('pages::admin.content.index')
        ->assertSee(['Unverified Tale', 'Verified Tale', 'Failed Tale', 'License of the unverified one', 'File pending', 'Failed: Not an EPUB', '3 reading files', 'Licensed', 'EPUB']);

    $page->set('show', 'unverified')->assertSee('Unverified Tale')->assertDontSee('Verified Tale')->assertSee('Failed Tale');
    $page->set('show', 'verified')->assertSee('Verified Tale')->assertDontSee('Unverified Tale')->assertDontSee('Failed Tale');
    $page->set('show', 'failed')->assertSee('Failed Tale')->assertDontSee('Verified Tale')->assertDontSee('Unverified Tale');
});

test('the empty states say what is missing', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.content.index')->assertSee('No reading files yet')
        ->set('show', 'failed')->assertSee('Nothing here');
});

test('the page links to the story and carries named controls', function () {
    $source = ContentSource::factory()->create();

    $html = $this->actingAs(User::factory()->admin()->create())->get(route('admin.content'))->assertOk()->getContent();

    expect($html)->toContain(route('admin.media.edit', $source->media))
        ->and($html)->toContain('aria-label="Verify '.e($source->media->title).'"')
        ->and($html)->toContain('data-label="License"');
});

test('the verified filter matches what counts as verified', function () {
    $admin = User::factory()->admin()->create();
    ContentSource::factory()->verified()->for(Media::factory()->state(['title' => 'Vouched Tale']))->create();
    // Stamped with a time but no admin: not verified, so it belongs under Unverified.
    ContentSource::factory()->for(Media::factory()->state(['title' => 'Half Stamped Tale']))->create(['verified_at' => now()]);

    $page = Livewire::actingAs($admin)->test('pages::admin.content.index');
    $page->set('show', 'verified')->assertSee('Vouched Tale')->assertDontSee('Half Stamped Tale');
    $page->set('show', 'unverified')->assertSee('Half Stamped Tale')->assertDontSee('Vouched Tale');
});

test('a stored file gets a preview link in the row menu, a source without one does not', function () {
    $this->actingAs(User::factory()->admin()->create());
    $withFile = ContentSource::factory()->ready()->create();
    $without = ContentSource::factory()->create();

    $html = Livewire::test('pages::admin.content.index')->html();

    expect($html)->toContain('data-test="preview-'.$withFile->id.'"')
        ->and($html)->toContain('/admin/content/'.$withFile->id.'/preview?')
        ->and($html)->not->toContain('data-test="preview-'.$without->id.'"');
});
