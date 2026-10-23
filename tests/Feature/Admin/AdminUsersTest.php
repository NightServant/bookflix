<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('non admins get 403', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.users'))->assertForbidden();
});

test('guests are redirected to login', function () {
    $this->get(route('admin.users'))->assertRedirect(route('login'));
});

test('the admin index links to the users page', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertSee(route('admin.users'), false);
});

test('admin sees active and deactivated users with the right status', function () {
    $active = User::factory()->create(['name' => 'Alice Active']);
    $gone = User::factory()->deactivated()->create(['name' => 'Dan Deactivated', 'deactivated_at' => '2026-03-05 10:00:00']);
    $unverified = User::factory()->unverified()->create(['name' => 'Una Unverified']);
    $this->actingAs($admin = User::factory()->admin()->create(['name' => 'Root Boss']));

    $page = Livewire::test('pages::admin.users.index')
        ->assertSee('Alice Active')->assertSee($active->email)
        ->assertSee('Dan Deactivated')->assertSee('Mar 5, 2026')
        ->assertSee('Una Unverified');

    $rows = collect(explode('<tr', $page->html()))
        ->mapWithKeys(fn (string $row) => preg_match('/wire:key="user-(\d+)"/', $row, $m) ? [(int) $m[1] => preg_replace('/\s+/', ' ', strip_tags($row))] : []);

    expect($rows[$active->id])->toMatch('/\bActive\b/')->not->toContain('Deactivated')->toContain('Yes')->not->toMatch('/\bAdmin\b/')
        ->and($rows[$gone->id])->toContain('Deactivated')->toContain('Mar 5, 2026')->not->toMatch('/\bActive\b/')
        ->and($rows[$unverified->id])->toContain('No')
        ->and($rows[$admin->id])->toMatch('/\bAdmin\b/');
});

test('search narrows by email and name', function () {
    User::factory()->create(['name' => 'Zed Findme', 'email' => 'zed@example.com']);
    User::factory()->create(['name' => 'Other Person', 'email' => 'needle@example.org']);
    User::factory()->create(['name' => 'Bystander', 'email' => 'bystander@example.net']);
    $this->actingAs(User::factory()->admin()->create(['name' => 'Root', 'email' => 'root@example.test']));

    Livewire::test('pages::admin.users.index')
        ->set('search', 'findme')->assertSee('Zed Findme')->assertDontSee('Other Person')->assertDontSee('Bystander')
        ->set('search', 'NEEDLE@')->assertSee('Other Person')->assertDontSee('Zed Findme')
        ->set('search', '%')->assertSee('No users found.');
});

test('the list is paginated at 25', function () {
    User::factory()->count(30)->sequence(fn ($sequence) => ['created_at' => now()->subDays(31 - $sequence->index)])->create();
    $this->actingAs(User::factory()->admin()->create());

    $page = Livewire::test('pages::admin.users.index');

    expect($page->instance()->users)->toHaveCount(25)->and($page->instance()->users->total())->toBe(31);

    $page->call('nextPage');

    expect($page->instance()->users)->toHaveCount(6);
});

test('no passwords, remember tokens or two factor secrets reach the html', function () {
    $user = User::factory()->withTwoFactor()->create(['remember_token' => 'remember-me-token-123']);
    $secret = $user->getRawOriginal('two_factor_secret');
    $codes = $user->getRawOriginal('two_factor_recovery_codes');
    $this->actingAs(User::factory()->admin()->create());

    $html = $this->get(route('admin.users'))->assertOk()->getContent();

    expect($html)->toContain($user->email)
        ->not->toContain($user->getRawOriginal('password'))
        ->not->toContain('remember-me-token-123')
        ->not->toContain($secret)
        ->not->toContain($codes)
        ->not->toContain('two_factor');
});

test('the query count stays bounded', function () {
    User::factory()->count(25)->create();
    $this->actingAs(User::factory()->admin()->create());

    DB::enableQueryLog();
    Livewire::test('pages::admin.users.index');
    $queries = count(DB::getQueryLog());

    expect($queries)->toBeLessThanOrEqual(3);
});

test('a demoted admin is refused on the next livewire request', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $page = Livewire::test('pages::admin.users.index');

    $admin->forceFill(['is_admin' => false])->save();

    $page->set('search', 'x')->assertForbidden();
});
