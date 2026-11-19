<?php

use App\Models\Collection;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

test('the schema holds no visibility or moderation columns: privacy is the rule (P10-R3)', function () {
    expect(Schema::hasColumn('profiles', 'profile_visibility'))->toBeFalse()
        ->and(Schema::hasColumn('profiles', 'library_visibility'))->toBeFalse()
        ->and(Schema::hasColumn('profiles', 'handle'))->toBeFalse()
        ->and(Schema::hasColumn('profiles', 'moderation_hidden_at'))->toBeFalse()
        ->and(Schema::hasColumn('collections', 'visibility'))->toBeFalse()
        ->and(Schema::hasColumn('collections', 'moderation_hidden_at'))->toBeFalse()
        ->and(Schema::hasTable('follows'))->toBeFalse();
});

test('a reader collection is viewable by its owner only', function () {
    $collection = Collection::factory()->create();
    $other = User::factory()->create();

    expect(Gate::forUser($collection->user)->allows('view', $collection))->toBeTrue()
        ->and(Gate::forUser($other)->allows('view', $collection))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', $collection))->toBeFalse()
        ->and(Collection::query()->viewableBy($other)->count())->toBe(0)
        ->and(Collection::query()->viewableBy($collection->user)->count())->toBe(1);
});

test('an editorial collection is viewable by every reader and a guest while its owner is active', function () {
    $admin = User::factory()->admin()->create();
    $pick = Collection::factory()->editorial()->create(['user_id' => $admin->id]);
    $other = User::factory()->create();

    expect(Gate::forUser($other)->allows('view', $pick))->toBeTrue()
        ->and(Gate::forUser(null)->allows('view', $pick))->toBeTrue()
        ->and(Collection::query()->viewableBy($other)->pluck('id')->all())->toBe([$pick->id]);

    $admin->forceFill(['deactivated_at' => now()])->save();

    expect(Gate::forUser($other)->allows('view', $pick->fresh()))->toBeFalse()
        ->and(Collection::query()->viewableBy($other)->count())->toBe(0);
});

test('the migration ends the staff picks that were readers collections and keeps those of admins', function () {
    $admin = User::factory()->admin()->create();
    $adminPick = Collection::factory()->editorial()->create(['user_id' => $admin->id]);
    $readerPick = Collection::factory()->editorial()->create();
    $migration = require database_path('migrations/2026_11_19_090000_make_profiles_and_collections_private.php');

    // down() puts the columns back, up() takes them out again and ends the reader's pick.
    $migration->down();
    expect(Schema::hasColumn('collections', 'visibility'))->toBeTrue()->and(Schema::hasColumn('profiles', 'profile_visibility'))->toBeTrue();

    $migration->up();
    expect(Schema::hasColumn('collections', 'visibility'))->toBeFalse()
        ->and($adminPick->fresh()->is_editorial)->toBeTrue()
        ->and($readerPick->fresh()->is_editorial)->toBeFalse();
});
