<?php

use App\Catalog\Taxonomy\LegacySlugs;
use App\Catalog\Taxonomy\LegacyTaxonomy;
use App\Catalog\Taxonomy\LegacyTaxonomyMigrator;
use App\Enums\WarningStatus;
use App\Models\ContentWarning;
use App\Models\Genre;
use App\Models\Media;
use App\Models\Tag;
use App\Models\Theme;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('a fresh database is not seeded by the migrations', function () {
    expect(Genre::count())->toBe(0)->and(Theme::count())->toBe(0)->and(ContentWarning::count())->toBe(0);
});

/**
 * Media tagged the way the flat taxonomy did, before anything is seeded.
 *
 * @return array<string, Media>
 */
function legacyCatalog(): array
{
    $genre = fn (string $slug) => Genre::factory()->create(['slug' => $slug, 'name' => Str::headline($slug)]);
    $theme = fn (string $slug) => Theme::factory()->create(['slug' => $slug, 'name' => Str::headline($slug)]);

    $berserk = Media::factory()->manga()->create(['title' => 'Berserk']);
    $berserk->genres()->attach([$genre('action')->id, $genre('adventure')->id, $genre('fantasy')->id, $genre('ecchi')->id, $genre('supernatural')->id]);
    $berserk->themes()->attach([$theme('school')->id, $theme('war')->id, $theme('politics')->id, $theme('isekai')->id]);

    $dune = Media::factory()->novel()->create(['title' => 'Dune']);
    $dune->genres()->attach(Genre::where('slug', 'action')->first());
    $dune->themes()->attach(Theme::where('slug', 'school')->first());

    return compact('berserk', 'dune');
}

test('media tagged with a retired genre or theme slug are re-pointed at the node that replaced it, and the retired row goes', function () {
    ['berserk' => $berserk, 'dune' => $dune] = legacyCatalog();

    $this->seed(TaxonomySeeder::class);

    expect($berserk->genres()->pluck('slug')->all())->toEqualCanonicalizing(['action-and-adventure', 'fantasy', 'paranormal-fantasy', 'school-life', 'isekai'])
        ->and($berserk->themes()->pluck('slug')->all())->toEqualCanonicalizing(['war', 'political-intrigue'])
        ->and($dune->genres()->pluck('slug')->all())->toEqualCanonicalizing(['action-and-adventure', 'school-life'])
        ->and($dune->themes()->count())->toBe(0)
        ->and(Genre::whereIn('slug', ['action', 'adventure', 'ecchi', 'supernatural'])->count())->toBe(0)
        ->and(Theme::whereIn('slug', ['school', 'politics', 'isekai'])->count())->toBe(0);
});

test('two retired slugs that share a node leave a single link, not a clash', function () {
    ['berserk' => $berserk] = legacyCatalog();

    $this->seed(TaxonomySeeder::class);

    expect(DB::table('media_genres')->where('media_id', $berserk->id)->where('genre_id', Genre::where('slug', 'action-and-adventure')->value('id'))->count())->toBe(1);
});

test('a coarser replacement keeps the old term as a tag, a lossless one does not', function () {
    ['berserk' => $berserk, 'dune' => $dune] = legacyCatalog();

    $this->seed(TaxonomySeeder::class);

    expect($berserk->tags()->pluck('slug')->all())->toEqualCanonicalizing(['ecchi', 'supernatural', 'politics'])
        ->and($dune->tags()->count())->toBe(0);
});

test('a retired genre that is a content warning now becomes a present warning', function () {
    ['berserk' => $berserk] = legacyCatalog();

    $this->seed(TaxonomySeeder::class);

    $warning = $berserk->warnings()->where('slug', 'sexual-content')->sole();

    expect($warning->pivot->status)->toBe(WarningStatus::Present)->and($warning->pivot->source)->toBe('legacy')
        ->and($berserk->warnings()->count())->toBe(1);
});

test('running it again changes nothing and loses no media', function () {
    legacyCatalog();
    $this->seed(TaxonomySeeder::class);
    $before = [DB::table('media_genres')->count(), DB::table('media_themes')->count(), DB::table('media_tags')->count(), DB::table('media_content_warnings')->count()];

    $stats = app(LegacyTaxonomyMigrator::class)->run();
    $this->seed(TaxonomySeeder::class);

    expect([DB::table('media_genres')->count(), DB::table('media_themes')->count(), DB::table('media_tags')->count(), DB::table('media_content_warnings')->count()])->toBe($before)
        ->and($stats['retired'])->toBe(0);
});

test('the old content warning list becomes present warnings, an unknown name a tag, and the column stays', function () {
    $this->seed(TaxonomySeeder::class);
    $media = Media::factory()->novel()->create(['content_warnings' => ['Violence', 'Sexual assault', 'Cursing'], 'locked_fields' => ['content_warnings']]);
    $other = Media::factory()->novel()->create(['content_warnings' => []]);

    $stats = app(LegacyTaxonomyMigrator::class)->run();

    expect($stats['warnings'])->toBe(2)
        ->and($media->warnings->pluck('slug')->all())->toEqualCanonicalizing(['violence', 'non-consensual-sexual-acts'])
        ->and($media->warnings->every(fn ($warning) => $warning->pivot->status === WarningStatus::Present))->toBeTrue()
        ->and($media->tags()->pluck('name')->all())->toBe(['Cursing'])
        ->and($media->fresh()->content_warnings)->toBe(['Violence', 'Sexual assault', 'Cursing'])
        ->and($other->warnings()->count())->toBe(0)
        ->and(app(LegacyTaxonomyMigrator::class)->run()['warnings'])->toBe(0);
});

test('a warning nothing was recorded for is unknown, never absent', function () {
    $this->seed(TaxonomySeeder::class);
    $media = Media::factory()->novel()->create(['content_warnings' => ['Violence']]);
    app(LegacyTaxonomyMigrator::class)->run();

    $statuses = DB::table('media_content_warnings')->where('media_id', $media->id)->pluck('status', 'content_warning_id');

    expect($statuses)->toHaveCount(1)->and(ContentWarning::count())->toBe(30)
        ->and(DB::table('media_content_warnings')->where('status', 'absent')->count())->toBe(0);
});

test('a retired slug stays until its target is seeded, so no data is dropped on a half seeded database', function () {
    $media = Media::factory()->create();
    $media->genres()->attach(Genre::factory()->create(['slug' => 'action']));

    app(LegacyTaxonomyMigrator::class)->run();

    expect($media->genres()->pluck('slug')->all())->toBe(['action']);
});

test('old filter slugs are upgraded to the nodes that replaced them once the old rows are gone', function () {
    $this->seed(TaxonomySeeder::class);

    expect(app(LegacySlugs::class)->upgrade(['genres' => ['action', 'fantasy'], 'themes' => ['school', 'war', 'politics']]))
        ->toBe(['genres' => ['action-and-adventure', 'fantasy', 'school-life'], 'themes' => ['war', 'political-intrigue']])
        ->and(app(LegacySlugs::class)->upgrade(['genres' => ['ecchi'], 'themes' => []]))->toBe(['genres' => ['ecchi'], 'themes' => []])
        ->and(LegacyTaxonomy::resolve('genre', 'fantasy'))->toBeNull()
        ->and(LegacyTaxonomy::genreSlug('action'))->toBe('action-and-adventure')
        ->and(LegacyTaxonomy::genreSlug('ecchi'))->toBe('ecchi');
});

test('a retired slug that still has a row is left alone, and nothing is queried for current slugs', function () {
    Genre::factory()->create(['slug' => 'action']);

    DB::enableQueryLog();
    $untouched = app(LegacySlugs::class)->upgrade(['genres' => ['fantasy', 'isekai'], 'themes' => ['war']]);
    $queries = count(DB::getQueryLog());
    $kept = app(LegacySlugs::class)->upgrade(['genres' => ['action'], 'themes' => []]);

    expect($untouched)->toBe(['genres' => ['fantasy', 'isekai'], 'themes' => ['war']])->and($queries)->toBe(0)
        ->and($kept)->toBe(['genres' => ['action'], 'themes' => []]);
});

test('every retired slug points at a node of the vocabulary', function () {
    $this->seed(TaxonomySeeder::class);
    $tables = ['genre' => Genre::class, 'theme' => Theme::class, 'warning' => ContentWarning::class];

    foreach (LegacyTaxonomy::map() as $slugs) {
        foreach ($slugs as $old => $target) {
            expect($tables[$target['kind']]::where('slug', $target['slug'])->exists())->toBeTrue("{$old} -> {$target['kind']} {$target['slug']}");
        }
    }

    expect(Tag::count())->toBe(0);
});
