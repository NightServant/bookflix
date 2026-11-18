<?php

use App\Actions\Social\SetHandle;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

function handleError(User $user, ?string $input): ?string
{
    try {
        app(SetHandle::class)->handle($user, $input);
    } catch (ValidationException $e) {
        return $e->errors()['handle'][0];
    }

    return null;
}

test('a handle is lowercased and trimmed, and stored on the profile', function () {
    $user = User::factory()->create();

    expect(app(SetHandle::class)->handle($user, '  Ada_Lovelace '))->toBe('ada_lovelace')
        ->and($user->fresh()->profile->handle)->toBe('ada_lovelace');
});

test('the charset is lowercase letters, digits and underscore', function (string $handle) {
    expect(handleError(User::factory()->create(), $handle))->not->toBeNull();
})->with(['ada-lovelace', 'ada lovelace', 'ada.l', 'adä_l', 'ada/l', '@ada']);

test('the length is 3 to 32', function () {
    $user = User::factory()->create();

    expect(handleError($user, 'ab'))->not->toBeNull()
        ->and(handleError($user, str_repeat('a', 33)))->not->toBeNull()
        ->and(handleError($user, 'abc'))->toBeNull()
        ->and(handleError($user, str_repeat('a', 32)))->toBeNull();
});

test('reserved handles are refused in any case, including the app paths', function (string $handle) {
    expect(handleError(User::factory()->create(), $handle))->toBe('That handle is reserved. Choose another.');
})->with(['admin', 'Admin', 'SETTINGS', 'api', 'bookflix', 'library', 'livewire', 'recommendations', 'mod']);

test('every reserved handle that fits the charset is refused, and the list is lowercase', function () {
    foreach (config('bookflix.social.reserved_handles') as $reserved) {
        expect($reserved)->toBe(strtolower($reserved));
    }
});

test('uniqueness ignores case', function () {
    app(SetHandle::class)->handle(User::factory()->create(), 'Ada_L');

    expect(handleError(User::factory()->create(), 'ADA_l'))->toBe('That handle is already taken.');
});

test('a reader may save their own handle again', function () {
    $user = User::factory()->create();
    app(SetHandle::class)->handle($user, 'ada_l');

    expect(handleError($user, 'ADA_L'))->toBeNull();
});

test('changing a handle frees the old one', function () {
    $ada = User::factory()->create();
    $other = User::factory()->create();
    app(SetHandle::class)->handle($ada, 'ada_l');
    app(SetHandle::class)->handle($ada, 'ada_new');

    expect(handleError($other, 'ada_l'))->toBeNull()
        ->and($other->fresh()->profile->handle)->toBe('ada_l')
        ->and($ada->fresh()->profile->handle)->toBe('ada_new');
});

test('an empty handle clears it, unless the profile is public', function () {
    $user = User::factory()->create();
    $setHandle = app(SetHandle::class);
    $setHandle->handle($user, 'ada_l');

    expect($setHandle->handle($user, '  '))->toBeNull()
        ->and($user->fresh()->profile->handle)->toBeNull();

    $setHandle->handle($user, 'ada_l');
    $user->profile()->update(['profile_visibility' => 'public']);

    expect(handleError($user->fresh(), ''))->toBe('A public profile needs a handle. Make it private first.')
        ->and($user->fresh()->profile->handle)->toBe('ada_l');
});

test('the database keeps handles unique', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $a->profile()->updateOrCreate([], ['handle' => 'same_one']);

    expect(fn () => $b->profile()->updateOrCreate([], ['handle' => 'same_one']))->toThrow(UniqueConstraintViolationException::class);
});
