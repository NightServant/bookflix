<?php

use App\Classification\ClassificationService;
use App\Classification\DeweyCounts;
use App\Classification\PrimaryClassificationExists;
use App\Enums\ReviewStatus;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(DeweySeeder::class));

test('non admins get 403 on the review queue', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.classifications'))->assertForbidden();
});

test('the queue lists pending rows lowest confidence first with a status badge', function () {
    classified(Media::factory()->create(['title' => 'High']), '813', status: 'pending', confidence: 0.9);
    classified(Media::factory()->create(['title' => 'Low']), '813', status: 'pending', confidence: 0.5);
    classified(Media::factory()->create(['title' => 'Done']), '813', status: 'accepted', confidence: 0.1);
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('admin.classifications'))->assertSeeInOrder(['Low', 'High'])->assertSee('Pending')->assertDontSee('Done');
    Livewire::test('pages::admin.classifications.index')->set('status', 'accepted')->assertSee('Done')->assertSee('Accepted')->assertDontSee('High');
});

test('the queue is paginated', function () {
    classified(Media::factory()->count(26)->create(), '813', status: 'pending');
    $this->actingAs(User::factory()->admin()->create());

    expect(Livewire::test('pages::admin.classifications.index')->instance()->rows)->toHaveCount(25);
});

test('the admin index links to the queue', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.index'))->assertSee(route('admin.classifications'));
});

test('accept and reject record the reviewer and time', function () {
    $admin = User::factory()->admin()->create();
    $a = classified(Media::factory()->create(), '813', status: 'pending');
    $b = classified(Media::factory()->create(), '823', status: 'pending');
    $this->actingAs($admin);

    Livewire::test('pages::admin.classifications.index')->call('accept', $a->deweyClasses()->first()->id)->assertDispatched('toast-show')->call('reject', $b->deweyClasses()->first()->id);

    $ra = $a->deweyClasses()->first();
    $rb = $b->deweyClasses()->first();
    expect($ra->review_status)->toBe(ReviewStatus::Accepted)->and($ra->reviewed_by)->toBe($admin->id)->and($ra->reviewed_at)->not->toBeNull()
        ->and($rb->review_status)->toBe(ReviewStatus::Rejected)->and($rb->reviewed_by)->toBe($admin->id)->and($rb->reviewed_at)->not->toBeNull();
});

test('rejecting a stories only primary refreshes the counts', function () {
    $counts = app(DeweyCounts::class);
    $row = classified(Media::factory()->create(), '813', status: 'accepted')->deweyClasses()->first();
    expect($counts->forClass(dewey('813')))->toBe(1);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.classifications.index')->call('reject', $row->id);

    expect($counts->forClass(dewey('813')))->toBe(0);
});

test('changing the primary swaps it atomically', function () {
    $m = classified(Media::factory()->create(), '813', status: 'pending');
    $row = $m->deweyClasses()->first();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test('pages::admin.classifications.index')->call('change', $row->id, '823');

    $new = $m->deweyClasses()->where('review_status', 'accepted')->where('classification_type', 'primary')->sole();
    expect($row->fresh()->review_status)->toBe(ReviewStatus::Rejected)->and($row->fresh()->reviewed_by)->toBe($admin->id)
        ->and($new->dewey_class_id)->toBe(dewey('823')->id)->and($new->confidence)->toBe(1.0)->and($new->reviewed_by)->toBe($admin->id)
        ->and($m->deweyClasses()->where('classification_type', 'primary')->where('review_status', '!=', 'rejected')->count())->toBe(1);
});

test('changing to a class the story already had reuses that row', function () {
    $m = classified(Media::factory()->create(), '813', status: 'pending');
    $old = $m->deweyClasses()->first();
    $earlier = MediaDeweyClass::factory()->for($m)->create(['dewey_class_id' => dewey('823')->id, 'review_status' => 'rejected']);

    app(ClassificationService::class)->replace($old, dewey('823'), User::factory()->admin()->create());

    expect($m->deweyClasses()->count())->toBe(2)->and($earlier->fresh()->classification_type->value)->toBe('primary')->and($earlier->fresh()->review_status)->toBe(ReviewStatus::Accepted);
});

test('a failed swap leaves the original primary intact', function () {
    $m = classified(Media::factory()->create(), '813', status: 'pending');
    $row = $m->deweyClasses()->first();

    expect(fn () => app(ClassificationService::class)->replace($row, new DeweyClass, User::factory()->admin()->create()))->toThrow(Exception::class);

    expect($row->fresh()->review_status)->toBe(ReviewStatus::Pending)->and($m->deweyClasses()->count())->toBe(1);
});

test('two competing swaps cannot both leave a primary', function () {
    $m = classified(Media::factory()->create(), '813', status: 'pending');
    $first = $m->deweyClasses()->first();
    $stale = MediaDeweyClass::find($first->id);
    $svc = app(ClassificationService::class);
    $admin = User::factory()->admin()->create();

    $svc->replace($first, dewey('823'), $admin);

    expect(fn () => $svc->replace($stale, dewey('833'), $admin))->toThrow(PrimaryClassificationExists::class);
    expect($m->deweyClasses()->where('classification_type', 'primary')->where('review_status', '!=', 'rejected')->sole()->dewey_class_id)->toBe(dewey('823')->id)
        ->and($m->deweyClasses()->count())->toBe(2);
});

test('a refused swap tells the admin instead of failing', function () {
    $m = classified(Media::factory()->create(), '813', status: 'pending');
    $first = $m->deweyClasses()->first();
    $this->actingAs(User::factory()->admin()->create());
    $page = Livewire::test('pages::admin.classifications.index');
    app(ClassificationService::class)->replace($first, dewey('823'), User::factory()->admin()->create());

    $page->call('change', $first->id, '833')->assertDispatched('toast-show');

    expect($m->deweyClasses()->count())->toBe(2);
});

test('a secondary cannot be swapped onto the stories primary', function () {
    $m = classified(Media::factory()->create(), '813');
    classified($m, '500', type: 'secondary');
    $secondary = $m->deweyClasses()->where('classification_type', 'secondary')->first();

    expect(fn () => app(ClassificationService::class)->replace($secondary, dewey('813'), User::factory()->admin()->create()))->toThrow(PrimaryClassificationExists::class);
    expect($m->deweyClasses()->where('classification_type', 'primary')->count())->toBe(1)->and($secondary->fresh()->review_status)->toBe(ReviewStatus::Accepted);
});

test('an unknown code is refused with an error', function () {
    $row = classified(Media::factory()->create(), '813', status: 'pending')->deweyClasses()->first();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.classifications.index')->call('change', $row->id, '999.99')->assertHasErrors('code');

    expect($row->fresh()->review_status)->toBe(ReviewStatus::Pending);
});

test('the dialog changes a class from the picked code', function () {
    $m = classified(Media::factory()->create(), '813', status: 'pending');
    $row = $m->deweyClasses()->first();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.classifications.index')
        ->call('openChange', $row->id)->set('code', '823')->call('save')->assertHasNoErrors();

    expect($row->fresh()->review_status)->toBe(ReviewStatus::Rejected);
});

test('an admin can add a secondary class and remove a class', function () {
    $m = classified(Media::factory()->create(), '813');
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $lw = Livewire::test('pages::admin.classifications.index');

    $lw->call('add', $m->id, '500', 'secondary');
    expect($m->deweyClasses()->count())->toBe(2);
    $added = $m->deweyClasses()->where('classification_type', 'secondary')->first();
    expect($added->reviewed_by)->toBe($admin->id)->and($added->reviewed_at)->not->toBeNull()->and($added->review_status)->toBe(ReviewStatus::Accepted);

    $lw->call('remove', $added->id);
    expect($m->deweyClasses()->count())->toBe(1);
});

test('a fourth secondary class is refused', function () {
    $m = classified(Media::factory()->create(), '813');
    foreach (['500', '600', '700'] as $code) {
        classified($m, $code, type: 'secondary');
    }
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.classifications.index')->call('add', $m->id, '900', 'secondary');

    expect($m->deweyClasses()->count())->toBe(4);
});

test('adding a second primary is refused', function () {
    $m = classified(Media::factory()->create(), '813');
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.classifications.index')->call('add', $m->id, '823', 'primary');

    expect($m->deweyClasses()->count())->toBe(1);
});

test('every action rejects a non admin caller', function () {
    $row = classified(Media::factory()->create(), '813', status: 'pending')->deweyClasses()->first();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $page = fn () => Livewire::test('pages::admin.classifications.index');
    $calls = [
        ['accept', [$row->id]],
        ['reject', [$row->id]],
        ['change', [$row->id, '823']],
        ['add', [$row->media_id, '500', 'secondary']],
        ['remove', [$row->id]],
        ['openChange', [$row->id]],
        ['openAdd', [$row->media_id]],
        ['save', []],
    ];
    $pages = array_map(fn () => $page(), $calls);
    $admin->forceFill(['is_admin' => false])->save();

    foreach ($calls as $i => [$method, $args]) {
        $pages[$i]->call($method, ...$args)->assertForbidden();
    }

    expect($row->fresh()->review_status)->toBe(ReviewStatus::Pending)->and(MediaDeweyClass::count())->toBe(1);
});
