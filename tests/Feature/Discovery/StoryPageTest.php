<?php

use App\Catalog\Ingestion\Deduplicator;
use App\Discovery\SimilarMedia;
use App\Models\ExternalSource;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaExternalId;
use App\Models\Series;
use App\Models\Theme;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * @return array{Media, Media, Media, Media}
 */
function similarFixture(): array
{
    $base = Media::factory()->create(['title' => 'Base']);
    $sibling = Media::factory()->create(['title' => 'Sibling']);
    $sharesGenre = Media::factory()->create(['title' => 'Shares Genre']);
    $unrelated = Media::factory()->create(['title' => 'Unrelated']);

    $series = Series::factory()->create();
    $base->series()->attach($series, ['position' => 1]);
    $sibling->series()->attach($series, ['position' => 2]);

    $genre = Genre::factory()->create();
    $base->genres()->attach($genre);
    $sharesGenre->genres()->attach($genre);

    return [$base, $sibling, $sharesGenre, $unrelated];
}

test('the story page renders its sections', function () {
    $m = fullMedia();

    $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))
        ->assertOk()
        ->assertSee($m->title)
        ->assertSee('Duna')
        ->assertSee('Frank Herbert')
        ->assertSee('Chilton Books')
        ->assertSee('Science Fiction')
        ->assertSee('Politics')
        ->assertSee('Desert')
        ->assertSee('A desert planet and the spice that rules it.')
        ->assertSee('American Fiction')
        ->assertSee('Content warnings')
        ->assertSee('Violence')
        ->assertSee('Data from')
        ->assertSee('Book data from Open Library (openlibrary.org).')
        ->assertSee('Dune Messiah')
        ->assertSee('Dune: The Movie')
        ->assertSee('Adaptation');
});

test('the story page has no add to library cta until the library route exists', function () {
    $m = fullMedia();
    $url = route('stories.show', $m->slug);

    $this->actingAs(User::factory()->create())->get($url)->assertOk()
        ->assertDontSee('Add to Library')->assertDontSee('library-add');

    enableLibraryRoute();

    $this->actingAs(User::factory()->create())->get($url)->assertOk()
        ->assertSee('Add to Library')->assertSee('library-add', false);
});

test('the page has one h1, the title, and sets the document title', function () {
    $m = fullMedia();

    $html = $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(htmlQuery($html, '//h1')->item(0)->textContent)->toContain('Dune')
        ->and(htmlQuery($html, '//title')->item(0)->textContent)->toContain('Dune');
});

test('the rating shows through the rating display', function () {
    $m = fullMedia();

    $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))
        ->assertSee('Rated 4.5 out of 5 from 120 ratings');
});

test('tabs lead the series and related content, only for what the story has', function () {
    $m = fullMedia();
    $plain = Media::factory()->create();

    $full = $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))->getContent();
    $bare = $this->get(route('stories.show', $plain->slug))->getContent();

    $tabs = fn (string $html): array => collect(htmlQuery($html, '//*[@role="tab"]'))->map(fn ($tab) => trim($tab->textContent))->all();

    expect($tabs($full))->toBe(['Overview', 'Series', 'Related'])
        ->and($tabs($bare))->toBe(['Overview']);
});

test('genres and themes link to search with that filter, tags to a text search', function () {
    $m = fullMedia();

    $html = $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))->getContent();

    expect(htmlQuery($html, '//a[@href="'.e(route('search', ['filters' => ['genres' => ['science-fiction']]])).'"]')->length)->toBeGreaterThan(0)
        ->and(htmlQuery($html, '//a[@href="'.e(route('search', ['filters' => ['themes' => ['politics']]])).'"]')->length)->toBeGreaterThan(0)
        ->and(htmlQuery($html, '//a[@href="'.e(route('search', ['q' => 'Desert'])).'"]')->length)->toBeGreaterThan(0);
});

test('the classification links to explore and shows only public classes', function () {
    $m = fullMedia();
    classified($m, '823', type: 'secondary');
    classified($m, '833', type: 'secondary', status: 'rejected');

    $html = $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))->getContent();

    expect(htmlQuery($html, '//a[@href="'.route('explore.show', '813').'"]')->length)->toBeGreaterThan(0)
        ->and(htmlQuery($html, '//a[@href="'.route('explore.show', '823').'"]')->length)->toBeGreaterThan(0)
        ->and($html)->not->toContain('German Fiction');
});

test('hidden classifications are not shown on the page', function () {
    (new DeweySeeder)->run();
    $m = classified(Media::factory()->create(), '813', status: 'rejected');

    $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))->assertDontSee('Literature');
});

test('the breadcrumb sits above the title', function () {
    $m = fullMedia();

    $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))
        ->assertSeeInOrder(['Breadcrumb', 'Explore the Library', '800 Literature', '810 American Literature', '813 American Fiction', '<h1'], false);
});

test('the content warnings accordion is absent when there are none', function () {
    $m = Media::factory()->create(['content_warnings' => []]);
    $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))->assertDontSee('Content warnings');
});

test('attribution is left out for a source without one', function () {
    $m = Media::factory()->create();
    MediaExternalId::factory()->create(['media_id' => $m->id, 'external_source_id' => ExternalSource::factory()->create(['attribution' => ''])->id]);

    $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))->assertDontSee('Data from');
});

test('series neighbours link in both directions and are absent for the first/last or standalone story', function () {
    $series = Series::factory()->create(['title' => 'Trilogy']);
    [$one, $two, $three] = Media::factory()->count(3)->sequence(['title' => 'Part One'], ['title' => 'Part Two'], ['title' => 'Part Three'])->create();
    $one->series()->attach($series, ['position' => 1]);
    $two->series()->attach($series, ['position' => 2]);
    $three->series()->attach($series, ['position' => 3]);
    $alone = Media::factory()->create();

    $this->actingAs(User::factory()->create());

    $nav = fn (Media $media): array => collect(htmlQuery($this->get(route('stories.show', $media->slug))->getContent(), '//nav[@aria-label="Series navigation"]//a'))
        ->map(fn ($link) => [trim(preg_replace('/\s+/', ' ', $link->textContent)), $link->getAttribute('href')])->all();

    expect($nav($two))->toBe([['Previous in series Part One', route('stories.show', $one->slug)], ['Next in series Part Three', route('stories.show', $three->slug)]])
        ->and($nav($one))->toBe([['Next in series Part Two', route('stories.show', $two->slug)]])
        ->and($nav($three))->toBe([['Previous in series Part Two', route('stories.show', $two->slug)]])
        ->and($nav($alone))->toBe([]);
});

test('the series tab lists the volumes in reading order, those without a position last', function () {
    $series = Series::factory()->create();
    [$late, $early, $loose] = Media::factory()->count(3)->sequence(['title' => 'Late'], ['title' => 'Early'], ['title' => 'Loose'])->create();
    $late->series()->attach($series, ['position' => 2]);
    $early->series()->attach($series, ['position' => 1]);
    $loose->series()->attach($series, ['label' => 'Omnibus']);

    $this->actingAs(User::factory()->create())->get(route('stories.show', $early->slug))
        ->assertSeeInOrder(['[01]', 'Early', '[02]', 'Late', '[Omnibus]', 'Loose']);
});

test('related stories show their relation', function () {
    $media = Media::factory()->create();
    $media->relations()->attach(Media::factory()->create(['title' => 'The Prequel']), ['relation' => 'prequel']);
    $media->relations()->attach(Media::factory()->create(['title' => 'The Spin Off']), ['relation' => 'spin_off']);

    $this->actingAs(User::factory()->create())->get(route('stories.show', $media->slug))
        ->assertSee('The Prequel')->assertSee('[Prequel]', false)->assertSee('[Spin Off]', false);
});

test('a soft deleted story is a 404', function () {
    $m = Media::factory()->create();
    $m->delete();
    $this->actingAs(User::factory()->create())->get(route('stories.show', $m->slug))->assertNotFound();
});

test('a merged slug redirects with 301', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    app(Deduplicator::class)->merge($winner, $loser);
    $this->actingAs(User::factory()->create())->get(route('stories.show', $loser->slug).'?sq=5&slug=x')
        ->assertStatus(301)->assertRedirect(route('stories.show', [$winner->slug, 'sq' => 5]));
});

test('a slug merged into a deleted story is a 404', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    app(Deduplicator::class)->merge($winner, $loser);
    $winner->delete();

    $this->actingAs(User::factory()->create())->get(route('stories.show', $loser->slug))->assertNotFound();
});

test('an unknown slug is a 404', function () {
    $this->actingAs(User::factory()->create())->get('/stories/nope')->assertNotFound();
});

test('guests are redirected to login', function () {
    $m = Media::factory()->create();

    $this->get(route('stories.show', $m->slug))->assertRedirect(route('login'));
});

test('similar media prefers series then shared genres and excludes itself', function () {
    [$base, $sibling, $sharesGenre, $unrelated] = similarFixture();
    expect(app(SimilarMedia::class)->for($base)->pluck('id')->all())->toBe([$sibling->id, $sharesGenre->id]);
});

test('similar media ranks by how many genres and themes are shared, then fills from the same division', function () {
    (new DeweySeeder)->run();
    $base = classified(Media::factory()->create(['title' => 'Base']), '813');
    [$genreA, $genreB] = Genre::factory()->count(2)->create();
    $theme = Theme::factory()->create();
    $base->genres()->attach([$genreA->id, $genreB->id]);
    $base->themes()->attach($theme);

    $one = Media::factory()->create(['title' => 'Shares one']);
    $one->genres()->attach($genreA);
    $three = Media::factory()->create(['title' => 'Shares three']);
    $three->genres()->attach([$genreA->id, $genreB->id]);
    $three->themes()->attach($theme);
    classified(Media::factory()->create(['title' => 'Same division']), '812');
    $elsewhere = classified(Media::factory()->create(['title' => 'Same class, other division']), '823');

    $titles = app(SimilarMedia::class)->for($base)->pluck('title')->all();

    expect($titles)->toBe(['Shares three', 'Shares one', 'Same division'])
        ->and($titles)->not->toContain($elsewhere->title);
});

test('similar media skips deleted and merged stories and honours the limit', function () {
    [$base, $sibling, $sharesGenre] = similarFixture();
    $sibling->delete();

    expect(app(SimilarMedia::class)->for($base)->pluck('id')->all())->toBe([$sharesGenre->id])
        ->and(app(SimilarMedia::class)->for($base, 0))->toHaveCount(0);
});

test('similar media comes with the relations a shelf tile and a preview read', function () {
    [$base] = similarFixture();

    $first = app(SimilarMedia::class)->for($base)->first();

    expect($first->relationLoaded('mediaType'))->toBeTrue()
        ->and($first->relationLoaded('publicPrimaryDewey'))->toBeTrue();
});

test('the story page runs a bounded number of queries', function () {
    $m = fullMedia();
    $this->actingAs(User::factory()->create());
    DB::enableQueryLog();
    $this->get(route('stories.show', $m->slug))->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(25);
});

test('the story page runs at most twenty queries once the similar ids are cached', function () {
    $m = fullMedia();
    $this->actingAs(User::factory()->create());
    $this->get(route('stories.show', $m->slug))->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('stories.show', $m->slug))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(20);
});

test('similar media ids are kept an hour as plain ids and the rows are loaded fresh', function () {
    [$base, $sibling, $sharesGenre] = similarFixture();

    expect(app(SimilarMedia::class)->for($base)->pluck('id')->all())->toBe([$sibling->id, $sharesGenre->id])
        ->and(Cache::get("similar:v1:{$base->id}:12"))->toBe([$sibling->id, $sharesGenre->id]);

    $sibling->delete();

    expect(app(SimilarMedia::class)->for($base)->pluck('id')->all())->toBe([$sharesGenre->id]);
});
