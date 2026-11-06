<?php

use App\Discovery\Recommendations\Candidate;
use App\Discovery\Recommendations\CandidatePool;
use App\Discovery\Recommendations\CandidateSource;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Collection;

function fixedSource(string $key, array $candidates): CandidateSource
{
    return new class($key, $candidates) implements CandidateSource
    {
        public int $calls = 0;

        public function __construct(private string $key, private array $candidates) {}

        public function key(): string
        {
            return $this->key;
        }

        public function candidatesFor(User $user, int $limit): Collection
        {
            $this->calls++;

            return collect($this->candidates);
        }
    };
}

test('a story proposed by several sources appears once with its best score and that source\'s reason', function () {
    $user = User::factory()->create();
    $story = Media::factory()->create();
    $pool = new CandidatePool([
        fixedSource('trending', [new Candidate($story->id, 'trending', 0.4, ReasonType::Trending)]),
        fixedSource('content_similar', [new Candidate($story->id, 'content_similar', 0.9, ReasonType::BecauseYouRead, 77)]),
        fixedSource('new_releases', [new Candidate($story->id, 'new_releases', 0.6, ReasonType::NewRelease)]),
    ]);

    $gathered = $pool->gather($user);

    expect($gathered)->toHaveCount(1)
        ->and($gathered->first()->source)->toBe('content_similar')
        ->and($gathered->first()->score)->toBe(0.9)
        ->and($gathered->first()->reason)->toBe(ReasonType::BecauseYouRead)
        ->and($gathered->first()->reasonMediaId)->toBe(77);
});

test('library and dismissed stories are left out even when a source proposes them', function () {
    $user = User::factory()->create();
    [$owned, $hidden, $fine] = Media::factory()->count(3)->create();
    libraryEntry($user, $owned, 'want_to_read');
    dismissed($user, $hidden);
    $pool = new CandidatePool([fixedSource('trending', collect([$owned, $hidden, $fine])->map(fn ($m) => new Candidate($m->id, 'trending', 0.5, ReasonType::Trending))->all())]);

    expect($pool->gather($user)->pluck('mediaId')->all())->toBe([$fine->id]);
});

test('the best candidates come first and the limit applies', function () {
    $user = User::factory()->create();
    $stories = Media::factory()->count(3)->create();
    $pool = new CandidatePool([fixedSource('trending', $stories->map(fn ($m, $i) => new Candidate($m->id, 'trending', 0.2 + $i * 0.1, ReasonType::Trending))->all())]);

    expect($pool->gather($user, 2)->pluck('mediaId')->all())->toBe([$stories[2]->id, $stories[1]->id]);
});

test('personalized sources run only for a reader who has personalization on', function () {
    $user = User::factory()->create();
    $user->profile->update(['personalization_enabled' => false]);
    $personal = fixedSource('content_similar', []);
    $general = fixedSource('trending', []);
    $pool = new CandidatePool([$personal, $general]);

    $pool->gather($user->fresh());

    expect($personal->calls)->toBe(0)->and($general->calls)->toBe(1);

    $pool->gather(User::factory()->create());

    expect($personal->calls)->toBe(1);
});

test('the container builds the pool from the eight tagged sources', function () {
    $user = User::factory()->create();
    Media::factory()->count(3)->create(['published_on' => now()->subDays(3), 'ratings_count' => 10]);

    expect(app(CandidatePool::class)->bySource($user))->toHaveKeys(['collaborative', 'content_similar', 'dewey_similar', 'editorial', 'exploration', 'new_releases', 'popular_in_genres', 'trending']);

    $user->profile->update(['personalization_enabled' => false]);

    expect(array_keys(app(CandidatePool::class)->bySource($user->fresh())))->toBe(['trending', 'new_releases', 'editorial']);
});
