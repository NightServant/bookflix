<?php

use App\Catalog\Taxonomy\Vocabulary;
use App\Models\ContentWarning;
use App\Models\Demographic;
use App\Models\Genre;
use App\Models\Media;
use App\Models\Theme;
use App\Models\Trope;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->seed(TaxonomySeeder::class));

test('the spec headings that are genre families become the roots of the tree', function () {
    $families = Genre::query()->families()->orderBy('sort')->pluck('name')->all();

    expect($families)->toHaveCount(17)
        ->and($families[0])->toBe('Fantasy')
        ->and($families[15])->toBe('Nonfiction and Narrative Nonfiction')
        ->and($families[16])->toBe('Mature, erotic and controversial categories');
});

test('every listed subgenre is a child of a family with its display group', function () {
    $isekai = Genre::where('slug', 'isekai')->with('parent')->first();

    expect($isekai->parent->slug)->toBe('fantasy')
        ->and($isekai->group)->toBe('Progression and Eastern fantasy')
        ->and(Genre::where('slug', 'high-fantasy')->first()->group)->toBe('High and epic fantasy')
        ->and(Genre::where('slug', 'biography')->first()->group)->toBeNull()
        ->and(Genre::query()->whereNotNull('parent_id')->count())->toBe(count(Vocabulary::genres()['genres']));
});

test('slugs are stable and unique across the tree', function () {
    $slugs = Genre::pluck('slug');

    expect($slugs->unique())->toHaveCount($slugs->count())
        ->and(Vocabulary::slug('Grumpy/Sunshine'))->toBe('grumpy-sunshine')
        ->and(Vocabulary::slug('Shōnen'))->toBe('shonen')
        ->and(Genre::where('slug', 'bl-boys-love')->exists())->toBeTrue()
        ->and(Genre::where('slug', 'lgbtq-romance')->exists())->toBeTrue();
});

test('a subgenre listed under two families is one genre with a primary parent and an extra parent link', function (string $slug, string $primary, string $also) {
    $genre = Genre::where('slug', $slug)->with(['parent', 'secondaryParents'])->sole();

    expect(Genre::where('name', $genre->name)->count())->toBe(1)
        ->and($genre->parent->slug)->toBe($primary)
        ->and($genre->secondaryParents->pluck('slug')->all())->toContain($also);
})->with([
    'courtroom drama' => ['courtroom-drama', 'mystery-crime-and-thriller', 'drama-and-human-relationships'],
    'techno-thriller' => ['techno-thriller', 'science-fiction', 'mystery-crime-and-thriller'],
    'romantic comedy' => ['romantic-comedy', 'romance', 'comedy-and-satire'],
    'historical fantasy' => ['historical-fantasy', 'fantasy', 'historical-war-and-western-fiction'],
    'fairy tales' => ['fairy-tales', 'mythology-folklore-and-religion', 'childrens-young-adult-and-new-adult'],
]);

test('a subgenre listed twice under the same family is not linked to its own parent', function () {
    $genre = Genre::where('slug', 'workplace-romance')->with('secondaryParents')->sole();

    expect($genre->parent->slug)->toBe('romance')->and($genre->secondaryParents)->toHaveCount(0);
});

test('a genre is mature-gated only when its primary parent is the mature family', function () {
    $mature = fn (string $slug): bool => Genre::where('slug', $slug)->value('is_mature');

    expect($mature('mature-erotic-and-controversial-categories'))->toBeTrue()
        ->and($mature('erotica'))->toBeTrue()
        ->and($mature('forbidden-romance'))->toBeTrue()
        ->and($mature('non-consensual-acts-depicted-in-fiction'))->toBeTrue()
        ->and($mature('romance'))->toBeFalse()
        ->and($mature('fantasy'))->toBeFalse()
        ->and($mature('slow-burn'))->toBeFalse()
        ->and(Genre::visible()->where('slug', 'erotica')->exists())->toBeFalse()
        ->and(Genre::visible(true)->where('slug', 'erotica')->exists())->toBeTrue();
});

test('a genre listed first elsewhere stays visible in its family, and the mature family still lists it', function (string $slug, string $primary) {
    $genre = Genre::where('slug', $slug)->with(['parent', 'secondaryParents'])->sole();

    expect($genre->is_mature)->toBeFalse()
        ->and($genre->parent->slug)->toBe($primary)
        ->and($genre->secondaryParents->pluck('slug')->all())->toBe(['mature-erotic-and-controversial-categories'])
        ->and(Genre::visible()->where('slug', $slug)->exists())->toBeTrue();
})->with([
    'betrayal' => ['betrayal', 'drama-and-human-relationships'],
    'dark romance' => ['dark-romance', 'romance'],
    'villain romance' => ['villain-romance', 'romance'],
    'obsessive romance' => ['obsessive-romance', 'romance'],
    'erotic thriller' => ['erotic-thriller', 'mystery-crime-and-thriller'],
    'splatterpunk' => ['splatterpunk', 'horror'],
]);

test('themes, tropes, demographics and warnings are seeded as their own facets', function () {
    expect(Theme::where('slug', 'found-family')->exists())->toBeTrue()
        ->and(Trope::where('slug', 'found-family')->value('group'))->toBe('Romance tropes')
        ->and(Genre::where('slug', 'found-family')->exists())->toBeTrue()
        ->and(Trope::where('slug', 'secret-identity')->count())->toBe(1)
        ->and(Trope::where('slug', 'chosen-one')->value('group'))->toBe('Fantasy and progression tropes')
        ->and(Demographic::orderBy('sort')->pluck('slug')->all())->toBe(['kodomomuke', 'shonen', 'shojo', 'seinen', 'josei', 'young-adult', 'new-adult', 'adult-literary-audience'])
        ->and(Demographic::where('slug', 'shonen')->value('name'))->toBe('Shōnen')
        ->and(ContentWarning::count())->toBe(30)
        ->and(ContentWarning::where('slug', 'non-consensual-sexual-acts')->exists())->toBeTrue();
});

test('seeding again changes nothing and keeps an edited description', function () {
    Genre::where('slug', 'isekai')->update(['description' => 'Pulled into another world.']);
    $before = [Genre::count(), Theme::count(), Trope::count(), Demographic::count(), ContentWarning::count(), DB::table('genre_secondary_parents')->count()];
    $ids = Genre::pluck('id', 'slug');

    $this->seed(TaxonomySeeder::class);

    expect([Genre::count(), Theme::count(), Trope::count(), Demographic::count(), ContentWarning::count(), DB::table('genre_secondary_parents')->count()])->toBe($before)
        ->and(Genre::pluck('id', 'slug')->all())->toEqual($ids->all())
        ->and(Genre::where('slug', 'isekai')->value('description'))->toBe('Pulled into another world.');
});

test('seeding adopts a flat genre of the same slug into the tree without touching its id or media', function () {
    $old = Genre::where('slug', 'slice-of-life')->sole();
    $media = Media::factory()->create();
    $media->genres()->attach($old);
    $old->update(['parent_id' => null, 'group' => null, 'sort' => 0]);

    $this->seed(TaxonomySeeder::class);

    expect(Genre::where('slug', 'slice-of-life')->sole()->id)->toBe($old->id)
        ->and(Genre::where('slug', 'slice-of-life')->sole()->parent->slug)->toBe('slice-of-life-and-everyday-fiction')
        ->and($media->genres()->pluck('slug')->all())->toBe(['slice-of-life']);
});

test('no label for sexual content involving minors exists anywhere in the vocabulary', function () {
    $forbidden = '/\b(lolicon|loli|shotacon|shota|pedophil\w*|paedophil\w*|underage|child[- ]?porn\w*|csam|jailbait)\b/i';
    $names = collect(Vocabulary::rows())->pluck('name')
        ->merge(Genre::pluck('name'))->merge(Theme::pluck('name'))->merge(Trope::pluck('name'))->merge(ContentWarning::pluck('name'))->merge(Demographic::pluck('name'));

    expect($names->filter(fn (string $name): bool => preg_match($forbidden, $name) === 1)->all())->toBe([]);
});

test('the vocabulary file holds every item of the spec', function () {
    $spec = base_path('docs/superpowers/plans/genre-taxonomy-spec.md');

    if (! is_file($spec)) {
        $this->markTestSkipped('The spec is not deployed here.');
    }

    $expected = [];
    $section = null;

    foreach (file($spec, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^## (\d+)\./', $line, $match)) {
            $section = (int) $match[1];

            continue;
        }

        $kind = match (true) {
            $section === null => null,
            $section <= 16 || $section === 19 => 'genre',
            $section === 17 => 'theme',
            $section === 18 => 'trope',
            $section === 20 => 'warning',
            default => null,
        };

        if ($kind !== null && str_contains($line, ';') && ! str_starts_with($line, '-')) {
            foreach (array_filter(array_map('trim', explode(';', $line))) as $name) {
                $expected[$kind][Vocabulary::slug($name)] = $name;
            }
        }

        if ($section === 21 && preg_match('/^- Editorial demographic: (.+)$/', $line, $match)) {
            foreach (array_map('trim', explode(';', $match[1])) as $name) {
                $expected['demographic'][Vocabulary::slug($name)] = $name;
            }
        }
    }

    foreach ($expected as $kind => $items) {
        $missing = array_diff(array_keys($items), Vocabulary::slugs($kind));

        expect($missing)->toBe([], "{$kind} items of the spec missing from vocabulary.csv");
    }

    expect(count($expected))->toBe(5);
});

test('the data migration corrects a database seeded with the over-broad mature flag', function () {
    Genre::whereIn('slug', ['dark-romance', 'betrayal', 'splatterpunk'])->update(['is_mature' => true]);
    Genre::where('slug', 'erotica')->update(['is_mature' => false]);

    (require database_path('migrations/2026_10_31_000001_limit_mature_flag_to_the_mature_family.php'))->up();

    $mature = Genre::where('is_mature', true)->pluck('slug');

    expect($mature)->not->toContain('dark-romance', 'betrayal', 'splatterpunk')
        ->and($mature)->toContain('erotica', 'mature-erotic-and-controversial-categories')
        ->and(Genre::where('is_mature', true)->count())->toBe(30)
        ->and(Genre::where('parent_id', Genre::where('slug', 'mature-erotic-and-controversial-categories')->value('id'))->where('is_mature', false)->count())->toBe(0);
});

test('the data migration leaves the flags alone on a database without the mature family', function () {
    Genre::where('slug', 'mature-erotic-and-controversial-categories')->update(['slug' => 'renamed']);
    $before = Genre::where('is_mature', true)->count();

    (require database_path('migrations/2026_10_31_000001_limit_mature_flag_to_the_mature_family.php'))->up();

    expect(Genre::where('is_mature', true)->count())->toBe($before);
});
