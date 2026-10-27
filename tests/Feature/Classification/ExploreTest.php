<?php

use App\Classification\ClassificationService;
use App\Classification\DeweyCounts;
use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(DeweySeeder::class));

test('explore lists the ten classes with story counts', function () {
    classified(Media::factory()->count(2)->create(), '813');

    $html = $this->actingAs(User::factory()->create())->get(route('explore.index'))->assertOk()->assertSeeInOrder(['000', '100'])->getContent();

    expect($html)
        ->toMatch('#href="[^"]*/explore/800"[^>]*>\s*<span[^>]*>800</span>\s*<span[^>]*>Literature</span>\s*<span[^>]*>2 stories</span>#')
        ->toMatch('#href="[^"]*/explore/100"[^>]*>\s*<span[^>]*>100</span>\s*<span[^>]*>[^<]*</span>\s*<span[^>]*>0 stories</span>#');
});

test('explore headings are Title Case', function () {
    classified(Media::factory()->create(), '813');

    $this->actingAs(User::factory()->create())->get(route('explore.index'))->assertOk()
        ->assertSee('Browse by Subject')->assertDontSee('Browse by subject');
});

test('drilling down 800 to 810 to 813 shows breadcrumbs and children', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('explore.show', '800'))->assertOk()->assertSee('810');
    $this->get(route('explore.show', '813'))->assertOk()->assertSeeInOrder(['800', '810', '813']);
});

test('a section lists the stories in its subtree', function () {
    $in = classified(Media::factory()->create(['title' => 'In Subtree']), '813');
    $out = classified(Media::factory()->create(['title' => 'Elsewhere']), '510');
    $this->actingAs(User::factory()->create())->get(route('explore.show', '810'))->assertSee('In Subtree')->assertDontSee('Elsewhere');
});

test('pending below the public minimum and rejected are hidden everywhere', function () {
    classified(Media::factory()->create(['title' => 'Low']), '813', status: 'pending', confidence: 0.60);
    classified(Media::factory()->create(['title' => 'Nope']), '813', status: 'rejected', confidence: 0.95);
    classified(Media::factory()->create(['title' => 'Shown']), '813', status: 'pending', confidence: 0.85);
    $this->actingAs(User::factory()->create());

    $this->get(route('explore.show', '813'))->assertSee('Shown')->assertDontSee('Low')->assertDontSee('Nope');
    $this->get(route('explore.index'))->assertSee('Shown')->assertDontSee('Low')->assertDontSee('Nope');
    expect(app(DeweyCounts::class)->forClass(dewey('813')))->toBe(1);
});

test('only the primary class counts a story once', function () {
    $m = classified(Media::factory()->create(), '813');
    classified($m, '500', type: 'secondary');
    expect(app(DeweyCounts::class)->forClass(dewey('500')))->toBe(0)->and(app(DeweyCounts::class)->forClass(dewey('800')))->toBe(1);
});

test('an unknown class code is a 404 and guests are redirected', function () {
    $this->get(route('explore.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('explore.show', '999'))->assertNotFound();
});

test('assigning a class refreshes the cached counts', function () {
    $counts = app(DeweyCounts::class);
    expect($counts->forClass(dewey('800')))->toBe(0);

    app(ClassificationService::class)->assign(Media::factory()->create(), dewey('813'), ClassificationType::Primary, cand('813', 1.0, ClassificationMethod::Manual));

    expect($counts->forClass(dewey('800')))->toBe(1);
});

test('pages mark the current class and name each see-all link', function () {
    classified(Media::factory()->create(), '813');
    $this->actingAs(User::factory()->create());

    $this->get(route('explore.show', '813'))->assertSee('aria-current="page"', false)->assertSee('Dewey-inspired')->assertDontSee('DDC')->assertDontSee('Subsections');
    $this->get(route('explore.index'))->assertSee('aria-label="See all in Literature"', false);
});

test('deleting, restoring or merging a media refreshes the cached counts', function () {
    $counts = app(DeweyCounts::class);
    $media = classified(Media::factory()->create(), '813');
    expect($counts->forClass(dewey('800')))->toBe(1);

    $media->delete();
    expect($counts->forClass(dewey('800')))->toBe(0);

    $media->restore();
    expect($counts->forClass(dewey('800')))->toBe(1);
});

test('a page past the end shows the last page instead of an empty one', function () {
    classified(Media::factory()->count(25)->create(), '813');
    $last = Media::orderBy('title')->orderBy('id')->get()->last();

    $this->actingAs(User::factory()->create())->get(route('explore.show', ['code' => '813', 'page' => 5]))
        ->assertOk()->assertSee($last->title)->assertDontSee('No stories here yet.');
});

test('the class code of an explore page cannot be changed from the browser', function () {
    $page = Livewire::actingAs(User::factory()->create())->test('pages::explore.show', ['code' => '813']);

    expect(fn () => $page->set('code', '800'))->toThrow(CannotUpdateLockedPropertyException::class);
});

test('a class that disappears after the page loaded is a 404', function () {
    $page = Livewire::actingAs(User::factory()->create())->test('pages::explore.show', ['code' => '813']);
    DeweyClass::where('code', '813')->delete();

    $page->call('$refresh')->assertNotFound();
});
