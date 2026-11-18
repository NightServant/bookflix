<?php

use App\Models\Collection;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('a moderator-hidden collection is viewable only by its owner', function () {
    $collection = Collection::factory()->public()->create(['moderation_hidden_at' => now()]);
    $other = User::factory()->create();

    expect(Gate::forUser($other)->allows('view', $collection))->toBeFalse()
        ->and(Gate::forUser($collection->user)->allows('view', $collection))->toBeTrue()
        ->and(Collection::query()->viewableBy($other)->count())->toBe(0)
        ->and(Collection::query()->viewableBy($collection->user)->count())->toBe(1);
});

test('a public collection of an admin is not viewable, but a staff pick is', function () {
    $admin = User::factory()->admin()->create();
    $plain = Collection::factory()->public()->create(['user_id' => $admin->id]);
    $pick = Collection::factory()->public()->editorial()->create(['user_id' => $admin->id]);
    $other = User::factory()->create();

    expect(Gate::forUser($other)->allows('view', $plain))->toBeFalse()
        ->and(Gate::forUser($other)->allows('view', $pick))->toBeTrue()
        ->and(Collection::query()->viewableBy($other)->pluck('id')->all())->toBe([$pick->id]);
});

test('a plain public collection stays viewable', function () {
    $collection = Collection::factory()->public()->create();

    expect(Gate::forUser(User::factory()->create())->allows('view', $collection))->toBeTrue()
        ->and(Gate::forUser(null)->allows('view', $collection))->toBeTrue();
});
