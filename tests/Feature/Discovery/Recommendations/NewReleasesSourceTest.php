<?php

use App\Discovery\Recommendations\Sources\NewReleasesSource;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\MediaType;
use App\Models\User;
use App\Models\UserPreference;
use Database\Seeders\MediaTypeSeeder;

test('only releases of the last 90 days, the newest scoring highest', function () {
    $user = User::factory()->create();
    [$new, $older, $old, $future] = Media::factory()->count(4)->sequence(
        ['published_on' => today()->subDays(5)],
        ['published_on' => today()->subDays(60)],
        ['published_on' => today()->subDays(120)],
        ['published_on' => today()->addDays(10)],
    )->create();

    $c = app(NewReleasesSource::class)->candidatesFor($user, 10);

    expect($c->pluck('mediaId')->all())->toBe([$new->id, $older->id])
        ->and($c->first()->score)->toBeGreaterThan($c->last()->score)
        ->and($c->first()->reason)->toBe(ReasonType::NewRelease)
        ->and($c->first()->source)->toBe('new_releases');
});

test('preferred types and languages narrow the releases and none means all', function () {
    $user = User::factory()->create();
    [$manga, $novel, $french] = [
        Media::factory()->manga()->create(['published_on' => today()->subDays(3)]),
        Media::factory()->novel()->create(['published_on' => today()->subDays(4)]),
        Media::factory()->novel()->create(['published_on' => today()->subDays(5), 'language' => 'fr']),
    ];

    expect(app(NewReleasesSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$manga->id, $novel->id, $french->id]);

    UserPreference::create(['user_id' => $user->id, 'preferable_type' => 'media_type', 'preferable_id' => MediaType::firstWhere('slug', 'novel')->id]);
    expect(app(NewReleasesSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$novel->id, $french->id]);

    $user->profile->update(['preferred_languages' => ['en']]);
    expect(app(NewReleasesSource::class)->candidatesFor($user->fresh(), 10)->pluck('mediaId')->all())->toBe([$novel->id]);
});

test('a story that is also a preferred type counts through its additional types', function () {
    $user = User::factory()->create();
    $comic = Media::factory()->graphicNovel()->create(['published_on' => today()->subDays(3)]);
    $manga = MediaType::firstOrCreate(['slug' => 'manga'], MediaTypeSeeder::TYPES['manga']);
    $comic->additionalTypes()->attach($manga);
    UserPreference::create(['user_id' => $user->id, 'preferable_type' => 'media_type', 'preferable_id' => $manga->id]);

    expect(app(NewReleasesSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$comic->id]);
});

test('library, dismissed, deleted and merged media are excluded', function () {
    $user = User::factory()->create();
    [$inLib, $dismissed, $deleted, $merged, $ok] = Media::factory()->count(5)->create(['published_on' => today()->subDays(2)]);
    libraryEntry($user, $inLib, 'reading');
    dismissed($user, $dismissed);
    $deleted->delete();
    $merged->update(['merged_into_id' => $ok->id]);

    expect(app(NewReleasesSource::class)->candidatesFor($user, 10)->pluck('mediaId')->all())->toBe([$ok->id]);
});

test('a mature release is left out until the reader opts in', function () {
    $mature = matureStory();
    $mature->update(['published_on' => today()->subDays(2)]);

    expect(app(NewReleasesSource::class)->candidatesFor(User::factory()->create(), 10))->toBeEmpty()
        ->and(app(NewReleasesSource::class)->candidatesFor(User::factory()->showsMature()->create(), 10)->pluck('mediaId')->all())->toBe([$mature->id]);
});
