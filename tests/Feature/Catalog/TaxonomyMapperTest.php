<?php

use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Data\ProviderRecord;
use App\Catalog\Ingestion\ProhibitedContent;
use App\Catalog\Ingestion\RecordValidator;
use App\Catalog\Ingestion\TaxonomyMapper;
use App\Catalog\Taxonomy\Vocabulary;
use App\Enums\AgeRating;
use App\Enums\WarningStatus;
use App\Models\ContentWarning;
use App\Models\Media;
use Database\Seeders\TaxonomySeeder;

const MAP_PROVIDERS = ['anilist', 'mangadex', 'mangaupdates', 'hardcover', 'metron', 'openlibrary'];

/**
 * @return array{kind: string, slug: string, provider: string, term: string}
 */
function mappingRows(): array
{
    $rows = [];

    foreach (MAP_PROVIDERS as $provider) {
        foreach (array_slice(file(database_path("data/taxonomy/{$provider}.csv"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), 1) as $line) {
            [$term, $kind, $slug] = str_getcsv($line, escape: '');
            $rows[] = compact('provider', 'term', 'kind', 'slug');
        }
    }

    return $rows;
}

test('every mapping row points at a node that exists, of the kind it names', function () {
    $families = array_column(Vocabulary::genres()['families'], 'slug');
    $known = [
        'genre' => $families,
        'subgenre' => array_column(Vocabulary::genres()['genres'], 'slug'),
        'theme' => Vocabulary::slugs('theme'),
        'trope' => Vocabulary::slugs('trope'),
        'warning' => Vocabulary::slugs('warning'),
        'demographic' => Vocabulary::slugs('demographic'),
        'rating' => array_map(fn (AgeRating $rating) => $rating->value, AgeRating::filterable()),
    ];
    $broken = [];

    foreach (mappingRows() as $row) {
        $ok = $row['kind'] === 'tag' ? $row['slug'] !== '' : in_array($row['slug'], $known[$row['kind']] ?? [], true);

        if (! $ok) {
            $broken[] = "{$row['provider']}: {$row['term']} -> {$row['kind']} {$row['slug']}";
        }
    }

    expect($broken)->toBe([]);
});

test('no mapping labels, or even names, sexual content involving minors', function () {
    $offending = collect(mappingRows())->filter(fn (array $row): bool => ProhibitedContent::matches($row['term']) || ProhibitedContent::matches($row['slug']))->all();

    expect($offending)->toBe([]);
});

test('a subgenre is stored alone: its family is implied, never added next to it', function () {
    $mapped = app(TaxonomyMapper::class)->mapTerms('anilist', ['Isekai', 'Cultivation']);

    expect($mapped->genres)->toBe(['isekai', 'cultivation-fantasy'])->and($mapped->genres)->not->toContain('fantasy');
});

test('one term can land on several facets', function () {
    $mapped = app(TaxonomyMapper::class)->mapTerms('anilist', ['Love Triangle', 'Body Horror', 'Reincarnation']);

    expect($mapped->genres)->toBe(['love-triangle', 'body-horror', 'reincarnation-fantasy'])
        ->and($mapped->tropes)->toBe(['love-triangle', 'reincarnation'])
        ->and($mapped->warnings)->toBe(['body-horror']);
});

test('demographics, content warnings and the audience rating come out of provider terms', function () {
    $mapped = app(TaxonomyMapper::class)->mapTerms('anilist', ['Shounen', 'Gore', 'Rape', 'Nudity', 'Ecchi', 'Male Protagonist']);

    expect($mapped->demographics)->toBe(['shonen'])
        ->and($mapped->warnings)->toBe(['gore', 'non-consensual-sexual-acts', 'nudity', 'sexual-content'])
        ->and($mapped->rating)->toBe(AgeRating::MatureTeen)
        ->and($mapped->tags)->toBe(['ecchi' => 'Ecchi', 'male-protagonist' => 'Male Protagonist']);
});

test('the stricter rating wins when several terms imply one', function () {
    expect(app(TaxonomyMapper::class)->mapTerms('mangaupdates', ['Ecchi', 'Smut'])->rating)->toBe(AgeRating::Mature)
        ->and(app(TaxonomyMapper::class)->mapTerms('mangaupdates', ['Comedy'])->rating)->toBeNull();
});

test('mangadex publication demographics map to the demographic facet', function () {
    $media = new NormalizedMedia(title: 'X', mediaTypeSlug: 'manga', demographics: ['shounen']);

    expect(app(TaxonomyMapper::class)->map('mangadex', $media)->demographics)->toBe(['shonen']);
});

test('hardcover content warnings are warnings, and one the vocabulary lacks is kept as a tag rather than lost', function () {
    $media = new NormalizedMedia(title: 'X', mediaTypeSlug: 'novel', contentWarnings: ['Violence', 'Misogyny', 'Cursing'], tags: ['Cursing']);

    $mapped = app(TaxonomyMapper::class)->map('hardcover', $media);

    expect($mapped->warnings)->toBe(['violence', 'discrimination'])->and($mapped->tags)->toBe(['cursing' => 'Cursing']);
});

test('open library subjects are matched loosely', function (string $subject, array $genres) {
    expect(app(TaxonomyMapper::class)->mapTerms('openlibrary', [$subject])->genres)->toBe($genres);
})->with([
    'fiction, fantasy, general' => ['Fiction, fantasy, general', ['fantasy']],
    'fantasy fiction' => ['Fantasy fiction', ['fantasy']],
    'a compound bisac subject' => ['Fiction, romance, historical', ['historical-romance']],
    'two parts, both mapped' => ['Fiction / Thrillers / Action & Adventure', ['mystery-crime-and-thriller', 'action-and-adventure']],
    'a listed compound subject' => ['Fiction, science fiction, action & adventure', ['interstellar-adventure']],
    'a plural' => ['Dystopias', ['dystopian-fiction']],
    'detective and mystery stories' => ['Detective and mystery stories', ['mystery-crime-and-thriller']],
    'case and punctuation' => ['SCIENCE FICTION.', ['science-fiction']],
    'a wrapper word' => ['Horror stories', ['horror']],
]);

test('catalogue housekeeping and generic subjects are not worth a tag, real unmapped ones are', function () {
    $mapped = app(TaxonomyMapper::class)->mapTerms('openlibrary', ['Fiction', 'Fiction, general', 'In library', 'Protected DAISY', 'nyt:combined-print-and-e-book-fiction=2018-01-07', 'Spice trade', 'Desert ecology']);

    expect($mapped->tags)->toBe(['spice-trade' => 'Spice trade', 'desert-ecology' => 'Desert ecology'])->and($mapped->genres)->toBe([]);
});

test('terms that name sexual content involving minors are dropped, never labelled or tagged', function (string $term) {
    foreach (['mangadex', 'mangaupdates', 'anilist'] as $provider) {
        $mapped = app(TaxonomyMapper::class)->mapTerms($provider, [$term, 'Comedy']);

        expect($mapped->tags)->toBe([])->and($mapped->genres)->toBe(['comedy-and-satire']);
    }
})->with(['Loli', 'Shota', 'Lolicon', 'Shotacon', 'loli']);

test('the prohibited term check matches whole words only', function () {
    expect(ProhibitedContent::matches('Loli'))->toBeTrue()
        ->and(ProhibitedContent::matches('Shota-con'))->toBeTrue()
        ->and(ProhibitedContent::matches('Child Pornography'))->toBeTrue()
        ->and(ProhibitedContent::matches('Shotaro Ishinomori'))->toBeFalse()
        ->and(ProhibitedContent::matches('Lollipop Chainsaw'))->toBeFalse()
        ->and(ProhibitedContent::matches('Lolita Fashion'))->toBeFalse()
        ->and(ProhibitedContent::matches('Child abuse'))->toBeFalse();
});

test('a record with a prohibited term is rejected at ingestion', function () {
    $outcome = importFixture('anilist', 'manga-berserk', mutate: ['tags' => [['name' => 'Loli', 'isMediaSpoiler' => false]]]);

    expect($outcome->value)->toBe('skipped')->and(Media::count())->toBe(0);
});

test('the validator checks every term list of a record', function (string $list) {
    $record = new ProviderRecord('test', '1', [], null, now()->toImmutable());
    $clean = normalized('Clean');
    $dirty = new NormalizedMedia(...['title' => 'Dirty', 'mediaTypeSlug' => 'novel', $list => ['Shotacon']]);

    expect(app(RecordValidator::class)->validate($record, $clean))->toBeTrue()
        ->and(app(RecordValidator::class)->validate($record, $dirty))->toBeFalse();
})->with(['genres', 'themes', 'tags', 'demographics', 'contentWarnings']);

test('importing a record attaches its demographics, tropes, warnings as present, rating and leftovers', function () {
    $this->seed(TaxonomySeeder::class);

    importFixture('anilist', 'manga-berserk', mutate: ['genres' => ['Action', 'Ecchi'], 'tags' => [
        ['name' => 'Seinen', 'isMediaSpoiler' => false], ['name' => 'Gore', 'isMediaSpoiler' => false],
        ['name' => 'Reincarnation', 'isMediaSpoiler' => false], ['name' => 'Male Protagonist', 'isMediaSpoiler' => false],
    ]]);

    $media = Media::first()->load(['genres', 'demographics', 'tropes', 'warnings', 'tags']);

    expect($media->genres->pluck('slug')->all())->toEqualCanonicalizing(['action-and-adventure', 'drama-and-human-relationships', 'fantasy', 'reincarnation-fantasy'])
        ->and($media->genres->firstWhere('slug', 'action-and-adventure')->isFamily())->toBeTrue()
        ->and($media->demographics->pluck('slug')->all())->toBe(['seinen'])
        ->and($media->tropes->pluck('slug')->all())->toBe(['reincarnation'])
        ->and($media->warnings->pluck('slug')->all())->toEqualCanonicalizing(['gore', 'sexual-content'])
        ->and($media->warnings->every(fn ($warning) => $warning->pivot->status === WarningStatus::Present && $warning->pivot->source === 'anilist'))->toBeTrue()
        ->and($media->age_rating)->toBe(AgeRating::MatureTeen)
        ->and($media->tags->pluck('slug')->all())->toEqualCanonicalizing(['ecchi', 'male-protagonist']);
});

test('a provider reporting a warning an admin verified absent leaves it disputed, and a locked warning list is left alone', function () {
    $this->seed(TaxonomySeeder::class);
    importFixture('anilist', 'manga-berserk');
    $media = Media::first();
    $gore = ContentWarning::where('slug', 'gore')->first();
    $media->warnings()->attach($gore, ['status' => 'absent', 'source' => 'admin']);

    importFixture('anilist', 'manga-berserk', mutate: ['description' => 'again', 'tags' => [['name' => 'Gore', 'isMediaSpoiler' => false]]]);

    expect($media->warnings()->where('slug', 'gore')->first()->pivot->status)->toBe(WarningStatus::Disputed);

    $media->update(['locked_fields' => ['content_warnings']]);
    $media->warnings()->detach();
    importFixture('anilist', 'manga-berserk', mutate: ['description' => 'a third time']);

    expect($media->warnings()->count())->toBe(0);
});
