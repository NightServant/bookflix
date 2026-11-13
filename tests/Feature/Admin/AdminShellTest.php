<?php

use App\Enums\DuplicateStatus;
use App\Models\DuplicateCandidate;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * The admin shell: a sidebar built from `config('bookflix.admin_navigation')` on every page an admin can open, and nothing of the reader shell.
 */

beforeEach(function () {
    Cache::forget('admin.dashboard-counts');
    Http::fake(['*/health' => Http::response(['status' => 'ok'])]);
});

dataset('admin shell pages', [
    'admin.index' => ['admin.index', fn () => [], 'Overview'],
    'admin.providers' => ['admin.providers', fn () => [], 'Sync'],
    'admin.media.index' => ['admin.media.index', fn () => [], 'Media'],
    'admin.media.edit' => ['admin.media.edit', fn () => ['media' => Media::factory()->create()], 'Media'],
    'admin.duplicates' => ['admin.duplicates', fn () => [], 'Duplicates'],
    'admin.classifications' => ['admin.classifications', fn () => [], 'Classification'],
    'admin.models' => ['admin.models', fn () => [], 'Models'],
    'admin.users' => ['admin.users', fn () => [], 'Users'],
]);

test('every admin page renders the whole admin navigation with the right current item', function (string $name, Closure $parameters, string $current) {
    $html = $this->actingAs(User::factory()->admin()->create())->get(route($name, $parameters()))->assertOk()->getContent();
    $items = adminNav($html);

    expect(array_column($items, 'label'))->toBe(['Overview', 'Sync', 'Media', 'Duplicates', 'Classification', 'Models', 'Users'])
        ->and(array_column($items, 'href'))->toBe([route('admin.index'), route('admin.providers'), route('admin.media.index'), route('admin.duplicates'), route('admin.classifications'), route('admin.models'), route('admin.users')])
        ->and(collect($items)->where('current', true)->pluck('label')->all())->toBe([$current]);
})->with('admin shell pages');

test('the admin pages carry none of the reader shell', function () {
    $html = $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertOk()->getContent();

    expect(primaryNavs($html))->toBe(['header' => '', 'bottom' => ''])
        ->and($html)->not->toContain('data-palette-panel')
        ->and($html)->toContain('Skip to content')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

test('the sidebar shows the numbers waiting for duplicates and classification', function () {
    DuplicateCandidate::factory()->count(3)->create();
    DuplicateCandidate::factory()->create(['status' => DuplicateStatus::Dismissed]);

    $items = collect(adminNav($this->actingAs(User::factory()->admin()->create())->get(route('admin.users'))->getContent()))->keyBy('label');

    expect($items['Duplicates']['count'])->toBe('3')
        ->and($items['Classification']['count'])->toBeNull()
        ->and($items['Sync']['count'])->toBeNull();
});

test('the sidebar counts are kept for a minute', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->get(route('admin.users'))->assertOk();

    expect(Cache::has('admin.dashboard-counts'))->toBeTrue();
});

test('the sidebar names the signed-in admin, links to the account page and signs out', function () {
    $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);

    $this->actingAs($admin)->get(route('admin.index'))->assertOk()
        ->assertSee('Ada Admin')
        ->assertSee('href="'.route('profile.edit').'"', false)
        ->assertSee('action="'.route('logout').'"', false)
        ->assertSee('Sign out');
});

test('an admin sees the admin shell on the profile and security pages, a reader does not', function () {
    $this->actingAs($admin = User::factory()->admin()->create());
    $this->withSession(['auth.password_confirmed_at' => time()]);

    foreach (['profile.edit', 'security.edit'] as $name) {
        $html = $this->get(route($name))->assertOk()->getContent();

        expect(adminNav($html))->toHaveCount(7)
            ->and($html)->not->toContain(route('preferences.edit'))
            ->and($html)->not->toContain(route('privacy.edit'))
            ->and(primaryNavs($html)['header'])->toBe('');
    }

    $this->actingAs(User::factory()->create());
    $this->withSession(['auth.password_confirmed_at' => time()]);

    foreach (['profile.edit', 'security.edit'] as $name) {
        $html = $this->get(route($name))->assertOk()->getContent();

        expect(adminNav($html))->toBe([])
            ->and(primaryNavs($html)['header'])->not->toBe('')
            ->and($html)->toContain(route('preferences.edit'));
    }
});

test('reader pages never render the admin navigation', function () {
    $this->actingAs(User::factory()->create());

    foreach (['home', 'discover', 'library.index'] as $name) {
        expect(adminNav($this->get(route($name))->assertOk()->getContent()))->toBe([]);
    }
});

test('the top-level admin pages have no way back, and the media edit page links up to Media', function () {
    $this->actingAs(User::factory()->admin()->create());

    expect($this->get(route('admin.providers'))->getContent())->not->toContain('>Admin</flux:button')
        ->and(htmlQuery($this->get(route('admin.users'))->getContent(), '//main//a[@href="'.route('admin.index').'"]')->length)->toBe(0);

    $media = Media::factory()->create();
    $this->get(route('admin.media.edit', $media))->assertSee('href="'.route('admin.media.index').'"', false);
});
