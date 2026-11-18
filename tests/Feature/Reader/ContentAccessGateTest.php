<?php

use App\Models\ContentSource;
use App\Models\Media;
use App\Models\User;
use App\Reader\ContentAccessGate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/*
 * The one rule for who may read a file (P9-R3) and who may preview it from admin (P9-R4). Every branch fails closed.
 */

beforeEach(function () {
    Storage::fake(ContentSource::disk());
    $this->gate = new ContentAccessGate;
});

test('a verified public domain file is allowed to any active reader', function () {
    $source = ContentSource::factory()->publicDomain()->verified()->ready()->create();

    expect($this->gate->allows(User::factory()->create(), $source))->toBeTrue()
        ->and($this->gate->allows(User::factory()->create(), $source))->toBeTrue();
});

test('a verified licensed file is allowed to any active reader', function () {
    expect($this->gate->allows(User::factory()->create(), ContentSource::factory()->licensed()->verified()->ready()->create()))->toBeTrue();
});

test('an unverified source is denied to everyone, the owner included', function () {
    $owner = User::factory()->create();

    expect($this->gate->allows($owner, ContentSource::factory()->publicDomain()->ready()->create()))->toBeFalse()
        ->and($this->gate->allows($owner, ContentSource::factory()->licensed()->ready()->create()))->toBeFalse()
        ->and($this->gate->allows($owner, ContentSource::factory()->userOwned($owner)->ready()->create(['verified_at' => null])))->toBeFalse();
});

test('a source verified by nobody is not verified, whatever the timestamp says', function () {
    $source = ContentSource::factory()->publicDomain()->ready()->create(['verified_at' => now()]);

    expect($this->gate->allows(User::factory()->create(), $source))->toBeFalse();
});

test('an uploaded file is for its owner only', function () {
    $owner = User::factory()->create();
    $source = ContentSource::factory()->userOwned($owner)->ready()->create();

    expect($this->gate->allows($owner, $source))->toBeTrue()
        ->and($this->gate->allows(User::factory()->create(), $source))->toBeFalse()
        ->and($this->gate->allows(User::factory()->admin()->create(), $source))->toBeFalse();
});

test('a deactivated reader is denied', function () {
    $source = ContentSource::factory()->publicDomain()->verified()->ready()->create();

    expect($this->gate->allows(User::factory()->deactivated()->create(), $source))->toBeFalse();
});

test('a soft-deleted story ends reading', function () {
    $source = ContentSource::factory()->publicDomain()->verified()->ready()->create();
    $source->media->delete();

    expect($this->gate->allows(User::factory()->create(), $source->fresh()))->toBeFalse();
});

test('a mature story is denied to a reader without mature access and allowed to one who has it', function () {
    $media = Media::factory()->create(['age_rating' => 'adult']);
    $source = ContentSource::factory()->for($media)->publicDomain()->verified()->ready()->create();

    expect($this->gate->allows(User::factory()->create(), $source))->toBeFalse()
        ->and($this->gate->allows(User::factory()->showsMature()->create(), $source))->toBeTrue();
});

test('an external link is never streamable', function () {
    $source = ContentSource::factory()->externalLink()->verified()->create();

    expect($this->gate->allows(User::factory()->create(), $source))->toBeFalse()
        ->and($this->gate->allows(User::factory()->create(), ContentSource::factory()->externalLink()->verified()->ready()->create()))->toBeFalse();
});

test('a file that is pending, failed or missing is denied', function () {
    $reader = User::factory()->create();

    expect($this->gate->allows($reader, ContentSource::factory()->publicDomain()->verified()->create()))->toBeFalse()
        ->and($this->gate->allows($reader, ContentSource::factory()->publicDomain()->verified()->ready()->create(['status' => 'failed'])))->toBeFalse()
        ->and($this->gate->allows($reader, ContentSource::factory()->publicDomain()->verified()->ready()->create(['status' => 'pending'])))->toBeFalse()
        ->and($this->gate->allows($reader, ContentSource::factory()->publicDomain()->verified()->ready()->create(['storage_path' => null])))->toBeFalse();
});

test('unverifying blocks access at once', function () {
    $reader = User::factory()->create();
    $source = ContentSource::factory()->publicDomain()->verified()->ready()->create();
    expect($this->gate->allows($reader, $source))->toBeTrue();

    $source->update(['verified_by' => null, 'verified_at' => null]);

    expect($this->gate->allows($reader, $source->fresh()))->toBeFalse();
});

test('an admin may preview an unverified file with a stored file, but never an upload or a link', function () {
    $admin = User::factory()->admin()->create();

    expect($this->gate->preview($admin, ContentSource::factory()->publicDomain()->ready()->create()))->toBeTrue()
        ->and($this->gate->preview($admin, ContentSource::factory()->licensed()->verified()->ready()->create()))->toBeTrue()
        ->and($this->gate->preview($admin, ContentSource::factory()->publicDomain()->create()))->toBeFalse()
        ->and($this->gate->preview($admin, ContentSource::factory()->userOwned(User::factory()->create())->ready()->create()))->toBeFalse()
        ->and($this->gate->preview($admin, ContentSource::factory()->externalLink()->create()))->toBeFalse();
});

test('a reader or a deactivated admin may not preview', function () {
    $source = ContentSource::factory()->publicDomain()->ready()->create();

    expect($this->gate->preview(User::factory()->create(), $source))->toBeFalse()
        ->and($this->gate->preview(User::factory()->admin()->deactivated()->create(), $source))->toBeFalse()
        ->and($this->gate->allows(User::factory()->admin()->create(), $source))->toBeFalse();
});

test('the policy delegates to the gate', function () {
    $source = ContentSource::factory()->publicDomain()->verified()->ready()->create();
    $hidden = ContentSource::factory()->publicDomain()->ready()->create();
    $reader = User::factory()->create();

    expect(Gate::forUser($reader)->allows('view', $source))->toBeTrue()
        ->and(Gate::forUser($reader)->allows('view', $hidden))->toBeFalse()
        ->and(Gate::forUser(User::factory()->deactivated()->create())->allows('view', $source))->toBeFalse();
});

test('deleting a source deletes its stored file', function () {
    $source = ContentSource::factory()->publicDomain()->ready()->create();
    Storage::disk(ContentSource::disk())->assertExists($source->storage_path);

    $source->delete();

    Storage::disk(ContentSource::disk())->assertMissing($source->storage_path);
});
