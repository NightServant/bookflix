<?php

use App\Catalog\Ingestion\Deduplicator;
use App\Enums\AgeRating;
use App\Enums\WarningStatus;
use App\Models\ContentWarning;
use App\Models\Demographic;
use App\Models\Genre;
use App\Models\Media;
use App\Models\Trope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a media shows only present and disputed warnings, never absent or unknown ones', function () {
    $media = Media::factory()->create();
    $warnings = collect(['violence', 'gore', 'nudity', 'torture'])->map(fn (string $slug) => ContentWarning::factory()->create(['slug' => $slug]));

    foreach ($warnings->zip(WarningStatus::cases()) as [$warning, $status]) {
        $media->warnings()->attach($warning, ['status' => $status->value, 'source' => 'test']);
    }

    expect($media->warnings)->toHaveCount(4)
        ->and($media->shownWarnings->pluck('slug')->all())->toBe(['violence', 'torture'])
        ->and($media->shownWarnings->first()->pivot->status)->toBe(WarningStatus::Present)
        ->and($media->warnings->firstWhere('slug', 'gore')->pivot->status)->toBe(WarningStatus::Absent);
});

test('a warning status outside the four is refused by the database', function () {
    $media = Media::factory()->create();
    $warning = ContentWarning::factory()->create();

    expect(fn () => DB::table('media_content_warnings')->insert(['media_id' => $media->id, 'content_warning_id' => $warning->id, 'status' => 'safe']))->toThrow(QueryException::class);
});

test('the audience rating widens the age rating to six levels and refuses anything else', function () {
    expect(array_map(fn (AgeRating $rating) => $rating->value, AgeRating::filterable()))->toBe(['all', 'children', 'teen', 'mature_teen', 'mature', 'adult'])
        ->and(AgeRating::MatureTeen->label())->toBe('Mature teen')
        ->and(AgeRating::Unknown->label())->toBeNull();

    foreach (AgeRating::cases() as $rating) {
        expect(Media::factory()->create(['age_rating' => $rating])->fresh()->age_rating)->toBe($rating);
    }

    expect(fn () => DB::table('media')->where('id', Media::factory()->create()->id)->update(['age_rating' => 'bogus']))->toThrow(QueryException::class);
});

test('a merge carries tropes, demographics and warnings, and reconciles the statuses of a shared warning', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    [$violence, $gore, $nudity] = ContentWarning::factory()->count(3)->create();
    $loser->tropes()->attach(Trope::factory()->create());
    $loser->demographics()->attach(Demographic::factory()->create());

    $winner->warnings()->attach([$violence->id => ['status' => 'present'], $gore->id => ['status' => 'unknown']]);
    $loser->warnings()->attach([$violence->id => ['status' => 'absent'], $gore->id => ['status' => 'present'], $nudity->id => ['status' => 'absent']]);

    app(Deduplicator::class)->merge($winner, $loser);

    $statuses = $winner->warnings()->get()->mapWithKeys(fn (ContentWarning $warning) => [$warning->id => $warning->pivot->status])->all();

    expect($winner->tropes()->count())->toBe(1)->and($winner->demographics()->count())->toBe(1)
        ->and($statuses)->toBe([$violence->id => WarningStatus::Disputed, $gore->id => WarningStatus::Present, $nudity->id => WarningStatus::Absent])
        ->and(DB::table('media_content_warnings')->where('media_id', $loser->id)->count())->toBe(0);
});

test('selecting a family includes its subgenres and the ones linked under it, but a subgenre does not select its siblings', function () {
    $fantasy = Genre::factory()->create(['slug' => 'fantasy']);
    $thriller = Genre::factory()->create(['slug' => 'thriller']);
    $drama = Genre::factory()->create(['slug' => 'drama']);
    $isekai = Genre::factory()->childOf($fantasy)->create(['slug' => 'isekai']);
    $litrpg = Genre::factory()->childOf($fantasy)->create(['slug' => 'litrpg']);
    $courtroom = Genre::factory()->childOf($thriller)->create(['slug' => 'courtroom-drama']);
    $courtroom->secondaryParents()->attach($drama, ['group' => null, 'sort' => 1]);

    $tag = fn (string $title, Genre $genre) => Media::factory()->create(['title' => $title])->genres()->attach($genre);
    $tag('Family', $fantasy);
    $tag('Isekai', $isekai);
    $tag('LitRPG', $litrpg);
    $tag('Courtroom', $courtroom);

    $titles = fn (array $slugs) => Media::query()->inGenres($slugs)->orderBy('title')->pluck('title')->all();

    expect($titles(['fantasy']))->toBe(['Family', 'Isekai', 'LitRPG'])
        ->and($titles(['isekai']))->toBe(['Isekai'])
        ->and($titles(['drama']))->toBe(['Courtroom'])
        ->and($titles(['thriller']))->toBe(['Courtroom'])
        ->and($titles(['isekai', 'drama']))->toBe(['Courtroom', 'Isekai'])
        ->and($titles(['no-such-genre']))->toBe([]);
});

test('slugs with quotes, commas, braces or backslashes are matched literally and cannot break the tree query', function () {
    $odd = Genre::factory()->create(['slug' => 'a"b,c}{d\\e']);
    Media::factory()->create(['title' => 'Odd'])->genres()->attach($odd);

    expect(Media::query()->inGenres(['a"b,c}{d\\e'])->pluck('title')->all())->toBe(['Odd'])
        ->and(Media::query()->inGenres(['a', 'b', "x\0y"])->count())->toBe(0);
});

test('excluding a warning drops present and disputed media and keeps unknown, absent and unmarked ones', function () {
    $gore = ContentWarning::factory()->create(['slug' => 'gore']);
    $make = fn (string $title, ?string $status) => tap(Media::factory()->create(['title' => $title]), fn (Media $media) => $status && $media->warnings()->attach($gore, ['status' => $status]));
    $make('Present', 'present');
    $make('Disputed', 'disputed');
    $make('Absent', 'absent');
    $make('Unknown', 'unknown');
    $make('Unmarked', null);

    expect(Media::query()->withoutWarnings(['gore'])->orderBy('title')->pluck('title')->all())->toBe(['Absent', 'Unknown', 'Unmarked']);
});
