<?php

use App\Enums\IngestionStatus;
use App\Jobs\SyncProvider;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('non admins get 403 on every admin catalog page', function (string $route) {
    $this->actingAs(User::factory()->create())->get(route($route))->assertForbidden();
})->with(['admin.providers', 'admin.media.index', 'admin.duplicates']);

test('the admin index links to the catalog pages', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))
        ->assertSee(route('admin.providers'), false)
        ->assertSee(route('admin.media.index'), false)
        ->assertSee(route('admin.duplicates'), false);
});

test('admins see providers and recent runs', function () {
    IngestionRun::factory()->create(['status' => IngestionStatus::Partial]);

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.providers'))->assertOk()->assertSee('partial');
});

test('only the latest 20 runs are listed', function () {
    IngestionRun::factory()->count(21)->sequence(fn ($sequence) => ['started_at' => now()->subMinutes(100 - $sequence->index)])->create();

    $this->actingAs(User::factory()->admin()->create());

    expect(Livewire::test('pages::admin.providers.index')->instance()->runs)->toHaveCount(20);
});

test('sync now dispatches the provider job once for an enabled source', function () {
    Queue::fake();
    ExternalSource::factory()->create(['key' => 'openlibrary', 'enabled' => true]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.providers.index')->call('syncNow', 'openlibrary')->call('syncNow', 'openlibrary');

    Queue::assertPushed(SyncProvider::class, fn (SyncProvider $job) => $job->providerKey === 'openlibrary');
    Queue::assertPushed(SyncProvider::class, 1);
});

test('sync now refuses a disabled or unknown source', function () {
    Queue::fake();
    ExternalSource::factory()->create(['key' => 'anilist', 'enabled' => false]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.providers.index')->call('syncNow', 'anilist')->call('syncNow', 'nope');

    Queue::assertNothingPushed();
});

test('an enabled source can be turned off and on', function () {
    $source = ExternalSource::factory()->create(['enabled' => true]);
    $this->actingAs(User::factory()->admin()->create());

    $page = Livewire::test('pages::admin.providers.index')->call('toggleEnabled', $source->id);
    expect($source->fresh()->enabled)->toBeFalse();

    $page->call('toggleEnabled', $source->id);
    expect($source->fresh()->enabled)->toBeTrue();
});

test('provider actions re-authorize on every call', function () {
    Queue::fake();
    $source = ExternalSource::factory()->create(['key' => 'openlibrary', 'enabled' => true]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $page = Livewire::test('pages::admin.providers.index');

    $admin->forceFill(['is_admin' => false])->save();

    $page->call('syncNow', 'openlibrary')->assertForbidden();
    Livewire::test('pages::admin.providers.index')->assertForbidden();

    Queue::assertNothingPushed();
    expect($source->fresh()->enabled)->toBeTrue();
});

test('the synthetic isbn source is hidden and cannot be enabled', function () {
    $isbn = ExternalSource::factory()->create(['key' => 'isbn', 'name' => 'ISBN', 'enabled' => false]);
    ExternalSource::factory()->create(['key' => 'openlibrary', 'name' => 'Open Library']);
    $this->actingAs(User::factory()->admin()->create());

    $page = Livewire::test('pages::admin.providers.index')->assertSee('Open Library')->assertDontSee('data-test="sync-now-isbn"', false);
    expect($page->instance()->sources->pluck('key')->all())->toBe(['openlibrary']);

    $page->call('toggleEnabled', $isbn->id)->assertNotFound();

    expect($isbn->fresh()->enabled)->toBeFalse();
});

test('the synthetic bookflix readers source is hidden and cannot be enabled either', function () {
    $readers = ExternalSource::factory()->create(['key' => 'bookflix', 'name' => 'Bookflix readers', 'enabled' => false]);
    ExternalSource::factory()->create(['key' => 'openlibrary', 'name' => 'Open Library']);
    $this->actingAs(User::factory()->admin()->create());

    $page = Livewire::test('pages::admin.providers.index')->assertDontSee('Bookflix readers');
    expect($page->instance()->sources->pluck('key')->all())->toBe(['openlibrary']);

    $page->call('toggleEnabled', $readers->id)->assertNotFound();

    expect($readers->fresh()->enabled)->toBeFalse();
});

test('a run lists the failed ids and why', function () {
    IngestionRun::factory()->create([
        'status' => IngestionStatus::Partial,
        'counts' => ['fetched' => 2, 'created' => 1, 'updated' => 0, 'merged' => 0, 'skipped' => 0, 'failed' => 1, 'failed_ids' => [['id' => 'OL42W', 'error' => 'SQLSTATE value too long']]],
    ]);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.providers.index')->assertSee('Failed ids (1)')->assertSee('OL42W: SQLSTATE value too long');
});
