<?php

use App\Catalog\Ingestion\Deduplicator;
use App\Models\Creator;
use App\Models\DuplicateCandidate;
use App\Models\ExternalSource;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaExternalId;
use Illuminate\Support\Facades\Schema;

test('the same work from two providers resolves to one media with two external ids', function () {
    $existing = Media::factory()->manga()->create(['title' => 'Berserk', 'publication_year' => 1989]);
    $existing->creators()->attach(Creator::factory()->create(['name' => 'Kentaro Miura']), ['role' => 'author', 'position' => 0]);
    MediaExternalId::factory()->for($existing)->create(['external_source_id' => ExternalSource::factory()->create(['key' => 'anilist']), 'external_id' => '30002']);

    $incoming = normalized(title: 'Berserk', type: 'manga', year: 1990, creators: ['Kentaro Miura'], provider: 'mangadex');

    expect(app(Deduplicator::class)->match($incoming)?->is($existing))->toBeTrue();
});

test('an exact isbn or provider cross id matches regardless of title', function () {
    $existing = Media::factory()->create(['title' => 'Dune']);
    $existing->externalIds()->save(MediaExternalId::factory()->make(['external_source_id' => ExternalSource::factory()->create(['key' => 'isbn'])->id, 'external_id' => '9780441172719']));
    $existing->externalIds()->save(MediaExternalId::factory()->make(['external_source_id' => ExternalSource::factory()->create(['key' => 'myanimelist'])->id, 'external_id' => '2']));

    expect(app(Deduplicator::class)->match(normalized(title: 'Dune (Deluxe Edition)', isbns: ['9780441172719']))?->is($existing))->toBeTrue()
        ->and(app(Deduplicator::class)->match(normalized(title: 'Something else', crossIds: ['myanimelist' => '2']))?->is($existing))->toBeTrue()
        ->and(app(Deduplicator::class)->match(normalized(title: 'Dune', isbns: ['9780000000000'], crossIds: ['myanimelist' => '3'])))->toBeNull();
});

test('monster the manga and an unrelated novel called monster never merge', function () {
    $manga = Media::factory()->manga()->create(['title' => 'Monster', 'publication_year' => 1994]);
    $manga->creators()->attach(Creator::factory()->create(['name' => 'Naoki Urasawa']), ['role' => 'author', 'position' => 0]);

    $novel = normalized(title: 'Monster', type: 'novel', year: 2011, creators: ['Walter Dean Myers']);

    expect(app(Deduplicator::class)->match($novel))->toBeNull()
        ->and(app(Deduplicator::class)->candidates($novel))->toBeEmpty();
});

test('same title and creator but a different year beyond the window does not auto merge but becomes a review candidate (D-21)', function () {
    $existing = Media::factory()->create(['title' => 'Akira', 'publication_year' => 1982]);
    $existing->creators()->attach(Creator::factory()->create(['name' => 'Katsuhiro Otomo']), ['role' => 'author', 'position' => 0]);

    expect(app(Deduplicator::class)->match(normalized(title: 'Akira', year: 2001, creators: ['Katsuhiro Otomo'])))->toBeNull();
    expect(app(Deduplicator::class)->candidates(normalized(title: 'Akira', year: 2001, creators: ['Katsuhiro Otomo'])))->toHaveCount(1);
});

test('titles compare without case, accents or punctuation', function () {
    $existing = Media::factory()->create(['title' => 'Amélie: The Story', 'publication_year' => 2001]);
    $existing->creators()->attach(Creator::factory()->create(['name' => 'Jean Dupré']), ['role' => 'author', 'position' => 0]);

    $incoming = normalized(title: 'AMELIE the story!', year: 2001, creators: ['jean dupre']);

    expect(app(Deduplicator::class)->match($incoming)?->is($existing))->toBeTrue();
});

test('similarity between 0.75 and 0.90 is returned as a review candidate', function () {
    Media::factory()->create(['title' => 'Fullmetal Alchemist Brotherhood']);
    $incoming = normalized(title: 'Fullmetal Alchemist Brotherhood Omnibus');

    $candidates = app(Deduplicator::class)->candidates($incoming);

    expect($candidates)->toHaveCount(1)->and($candidates->first()['score'])->toBeBetween(0.75, 0.9)
        ->and(app(Deduplicator::class)->match($incoming))->toBeNull();
});

test('recording the same candidate twice leaves one row', function () {
    [$first, $second] = Media::factory()->count(2)->create();
    $deduplicator = app(Deduplicator::class);

    $deduplicator->recordCandidates($first, collect([['media' => $second, 'score' => 0.8]]));
    $deduplicator->recordCandidates($first, collect([['media' => $second, 'score' => 0.82]]));
    $deduplicator->recordCandidates($second, collect([['media' => $first, 'score' => 0.82]]));

    expect(DuplicateCandidate::count())->toBe(1)
        ->and(DuplicateCandidate::first()->score)->toBe(0.82)
        ->and(Schema::hasIndex('duplicate_candidates', ['media_id', 'duplicate_media_id'], 'unique'))->toBeTrue();
});

test('merge moves external ids pivots and relations and soft deletes the loser', function () {
    [$winner, $loser] = Media::factory()->count(2)->create();
    $loser->externalIds()->save(MediaExternalId::factory()->make());
    $loser->genres()->attach(Genre::factory()->create());
    $loser->relations()->attach($winner, ['relation' => 'sequel']);
    $loser->update(['title' => 'Loser']);

    app(Deduplicator::class)->merge($winner, $loser);

    expect($winner->externalIds()->count())->toBe(1)
        ->and($winner->genres()->count())->toBe(1)
        ->and($winner->relations()->count())->toBe(0)
        ->and($loser->fresh()->trashed())->toBeTrue()
        ->and($loser->fresh()->merged_into_id)->toBe($winner->id);
});
