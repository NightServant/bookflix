<?php

use App\Discovery\Recommendations\Candidate;
use App\Discovery\Recommendations\Explanation;
use App\Discovery\Recommendations\HybridRanker;
use App\Discovery\Recommendations\RankingWeights;
use App\Enums\ReasonType;
use App\Models\Genre;
use App\Models\Media;
use App\Models\User;

function labelFor(User $user, Candidate $candidate): ?string
{
    return app(HybridRanker::class)->rank($user, collect([$candidate]), RankingWeights::active())->first()->explanation->label();
}

function openReader(): User
{
    $user = User::factory()->create();
    $user->profile->update(['show_mature' => true, 'adult_confirmed_at' => now()]);

    return $user->fresh();
}

test('fromCandidate carries the reason media title and the genre payload', function () {
    $m = Media::factory()->create(['title' => 'Dune']);

    expect(Explanation::fromCandidate(new Candidate(1, 'content_similar', .5, ReasonType::BecauseYouRead, $m->id), $m)->label())->toBe('Because you read Dune')
        ->and(Explanation::fromCandidate(new Candidate(1, 'popular_in_genres', .5, ReasonType::MatchesInterest, null, ['genre' => 'Fantasy']), null)->label())->toBe('Matches your interest in Fantasy')
        ->and(Explanation::fromCandidate(new Candidate(1, 'content_similar', .5, ReasonType::BecauseYouRead, 9), null)->label())->toBeNull();
});

test('the ranker names a reason title the reader may see', function () {
    $user = User::factory()->create();
    [$reason, $pick] = Media::factory()->count(2)->sequence(['title' => 'Dune'], [])->create();

    expect(labelFor($user, new Candidate($pick->id, 'content_similar', .5, ReasonType::BecauseYouRead, $reason->id)))->toBe('Because you read Dune');
});

test('no label names a reason title that is gated for the reader (F8)', function () {
    $gated = matureStory('Erotic Tale');
    $pick = Media::factory()->create();
    $candidate = new Candidate($pick->id, 'content_similar', .5, ReasonType::BecauseYouRead, $gated->id);
    $explanation = app(HybridRanker::class)->rank(User::factory()->create(), collect([$candidate]), RankingWeights::active())->first()->explanation;

    expect($explanation->label())->toBeNull()
        ->and($explanation->reasonTitle)->toBeNull()
        ->and(labelFor(openReader(), $candidate))->toBe('Because you read Erotic Tale');
});

test('an adult-rated reason title is gated too', function () {
    $adult = Media::factory()->create(['title' => 'Adults Only', 'age_rating' => 'adult']);
    $pick = Media::factory()->create();

    expect(labelFor(User::factory()->create(), new Candidate($pick->id, 'collaborative', .5, ReasonType::PopularWithSimilarReaders, $adult->id)))->toBeNull();
});

test('no label names a reason title that is soft-deleted, merged away or missing', function (string $how) {
    $user = User::factory()->create();
    [$reason, $pick, $keeper] = Media::factory()->count(3)->create();
    match ($how) {
        'deleted' => $reason->delete(),
        'merged' => $reason->update(['merged_into_id' => $keeper->id]),
        'missing' => $reason->forceDelete(),
    };

    expect(labelFor($user, new Candidate($pick->id, 'dewey_similar', .5, ReasonType::SimilarThemes, $reason->id)))->toBeNull();
})->with(['deleted', 'merged', 'missing']);

test('a reason type that needs a title but names none has no label', function () {
    $pick = Media::factory()->create();

    expect(labelFor(User::factory()->create(), new Candidate($pick->id, 'content_similar', .5, ReasonType::BecauseYouRead)))->toBeNull();
});

test('a genre the reader cannot see is not named (F8)', function (callable $make) {
    $genre = $make();
    $pick = Media::factory()->create();
    $candidate = new Candidate($pick->id, 'popular_in_genres', .5, ReasonType::MatchesInterest, null, ['genre' => $genre->name]);

    expect(labelFor(User::factory()->create(), $candidate))->toBeNull()
        ->and(labelFor(openReader(), $candidate))->toBe('Matches your interest in '.$genre->name);
})->with([
    'a mature genre' => [fn () => Genre::factory()->mature()->create(['name' => 'Erotica'])],
    'a subgenre of a mature family' => [function () {
        $family = Genre::factory()->mature()->create(['name' => 'Adult Family']);

        return Genre::factory()->create(['name' => 'Adult Sub', 'parent_id' => $family->id]);
    }],
]);

test('an ordinary genre is named for every reader', function () {
    $genre = Genre::factory()->create(['name' => 'Fantasy']);
    $pick = Media::factory()->create();
    $candidate = new Candidate($pick->id, 'popular_in_genres', .5, ReasonType::MatchesInterest, null, ['genre' => 'Fantasy']);

    expect(labelFor(User::factory()->create(), $candidate))->toBe('Matches your interest in Fantasy')
        ->and($genre->name)->toBe('Fantasy');
});

test('a genre payload naming no genre at all is not trusted', function () {
    $pick = Media::factory()->create();

    expect(labelFor(User::factory()->create(), new Candidate($pick->id, 'popular_in_genres', .5, ReasonType::MatchesInterest, null, ['genre' => 'Nonexistent'])))->toBeNull();
});
