<?php

use App\Catalog\Data\NormalizedMedia;
use App\Catalog\Ingestion\Deduplicator;
use App\Classification\DeweyCounts;
use App\Enums\ClassificationType;
use App\Enums\DuplicateStatus;
use App\Enums\ReviewStatus;
use App\Models\Creator;
use App\Models\DuplicateCandidate;
use App\Models\ExternalSource;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\MediaExternalId;
use App\Models\User;
use Database\Seeders\DeweySeeder;
use Database\Seeders\MediaTypeSeeder;
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

/**
 * A manhwa as MangaUpdates stores it, its creators with their names in Hangul too.
 */
function storedManhwa(string $title = 'Solo Leveling', int $year = 2018, string $provider = 'mangaupdates', string $type = 'manhwa'): Media
{
    app(MediaTypeSeeder::class)->run();
    $media = Media::factory()->ofType($type)->create(['title' => $title, 'publication_year' => $year]);
    $media->creators()->attach(Creator::factory()->create(['name' => 'Chugong (추공)']), ['role' => 'author', 'position' => 0]);
    $media->update(['primary_external_source_id' => ExternalSource::factory()->create(['key' => $provider])->id]);

    return $media;
}

test('a translation takes the form of the manhwa it translates, and D-21 still keeps the pair a review candidate', function () {
    storedManhwa();
    $translation = new NormalizedMedia(title: 'Solo Leveling', mediaTypeSlug: 'comic-book', credits: ['Chugong', 'DUBU', 'h-goon'], publicationYear: 2021);

    expect(app(Deduplicator::class)->originalForm($translation))->toBe('manhwa')
        ->and(app(Deduplicator::class)->match($translation))->toBeNull()
        ->and(app(Deduplicator::class)->candidates($translation))->toHaveCount(1);
});

test('a same titled comic is not taken for a translation without a shared creator, an earlier original, or an original from a provider that names the format', function (array $incoming, array $stored) {
    storedManhwa(...$stored);

    expect(app(Deduplicator::class)->originalForm(new NormalizedMedia(...['title' => 'Solo Leveling', 'mediaTypeSlug' => 'comic-book', ...$incoming])))->toBeNull();
})->with([
    'no shared creator (Marvel\'s Black Knight against the manga)' => [['credits' => ['Stan Lee'], 'publicationYear' => 2021], []],
    'published before the original' => [['credits' => ['Chugong'], 'publicationYear' => 2010], []],
    'the original came from Comic Vine itself' => [['credits' => ['Chugong'], 'publicationYear' => 2021], ['provider' => 'comicvine']],
    'the match is a Western comic' => [['credits' => ['Chugong'], 'publicationYear' => 2021], ['type' => 'comic-book']],
]);

test('creator names match across romanisations, name order and spacing', function (string $stored, string $credited) {
    app(MediaTypeSeeder::class)->run();
    $original = Media::factory()->manga()->create(['title' => 'Akira', 'publication_year' => 1982]);
    $original->creators()->attach(Creator::factory()->create(['name' => $stored]), ['role' => 'author', 'position' => 0]);
    $original->update(['primary_external_source_id' => ExternalSource::factory()->create(['key' => 'anilist'])->id]);

    expect(app(Deduplicator::class)->originalForm(new NormalizedMedia(title: 'Akira', mediaTypeSlug: 'comic-book', credits: [$credited], publicationYear: 1992)))->toBe('manga');
})->with([
    'a long vowel written out' => ['Katsuhiro Ootomo', 'Katsuhiro Otomo'],
    'family name first, with the name in kanji' => ['Oda Eiichirou (尾田栄一郎)', 'Eiichiro Oda'],
    'a pen name spaced differently' => ['sing N song (싱숑)', 'singNsong'],
]);

test('a record\'s own media is never its original, so a wrong type cannot keep itself', function () {
    $own = storedManhwa(type: 'manga');

    expect(app(Deduplicator::class)->originalForm(new NormalizedMedia(title: 'Solo Leveling', mediaTypeSlug: 'comic-book', credits: ['Chugong'], publicationYear: 2021), except: $own->id))->toBeNull();
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

test('merge rebuilds the winner search text and closes only the pair being merged', function () {
    [$winner, $loser, $other, $fourth] = Media::factory()->count(4)->create();
    $loser->creators()->attach(Creator::factory()->create(['name' => 'Naoki Urasawa']), ['role' => 'author', 'position' => 1]);
    $pair = DuplicateCandidate::factory()->create(['media_id' => $winner->id, 'duplicate_media_id' => $loser->id]);
    $unrelated = DuplicateCandidate::factory()->create(['media_id' => $winner->id, 'duplicate_media_id' => $other->id]);
    $dismissed = DuplicateCandidate::factory()->create(['media_id' => $loser->id, 'duplicate_media_id' => $other->id, 'status' => DuplicateStatus::Dismissed]);

    app(Deduplicator::class)->merge($winner, $loser, resolvedBy: User::factory()->create()->id);

    expect($winner->fresh()->search_text)->toContain('naoki urasawa')
        ->and($pair->fresh()->status)->toBe(DuplicateStatus::Merged)
        ->and($pair->fresh()->resolved_by)->not->toBeNull()
        ->and($unrelated->fresh()->status)->toBe(DuplicateStatus::Pending)
        ->and($dismissed->fresh()->status)->toBe(DuplicateStatus::Dismissed);
});

test('merge repoints the loser\'s unreviewed pairs at the winner and drops those the winner already has', function () {
    [$winner, $loser, $other, $fourth] = Media::factory()->count(4)->create();
    $moved = DuplicateCandidate::factory()->create(['media_id' => $loser->id, 'duplicate_media_id' => $fourth->id, 'score' => 0.8]);
    $reversed = DuplicateCandidate::factory()->create(['media_id' => $other->id, 'duplicate_media_id' => $loser->id, 'score' => 0.77]);
    $existing = DuplicateCandidate::factory()->create(['media_id' => $winner->id, 'duplicate_media_id' => $other->id, 'score' => 0.9]);

    app(Deduplicator::class)->merge($winner, $loser);

    expect($moved->fresh()->only(['media_id', 'duplicate_media_id', 'status']))->toBe(['media_id' => $winner->id, 'duplicate_media_id' => $fourth->id, 'status' => DuplicateStatus::Pending])
        ->and($reversed->fresh())->toBeNull()
        ->and($existing->fresh()->status)->toBe(DuplicateStatus::Pending)->and($existing->fresh()->score)->toBe(0.9)
        ->and(DuplicateCandidate::where('status', DuplicateStatus::Pending)->where(fn ($query) => $query->where('media_id', $loser->id)->orWhere('duplicate_media_id', $loser->id))->count())->toBe(0)
        ->and(DuplicateCandidate::count())->toBe(2);
});

test('merging into a winner that was merged away meanwhile lands on the live winner', function () {
    [$live, $stale, $loser] = Media::factory()->count(3)->create();
    $stale->update(['merged_into_id' => $live->id]);
    $stale->delete();
    $loser->genres()->attach(Genre::factory()->create());

    app(Deduplicator::class)->merge($stale, $loser);

    expect($loser->fresh()->merged_into_id)->toBe($live->id)->and($live->genres()->count())->toBe(1)->and($stale->fresh()->genres()->count())->toBe(0);
});

test('merging a loser that is already gone changes nothing and an outright removed winner is refused', function () {
    [$winner, $loser, $removed] = Media::factory()->count(3)->create();
    $loser->update(['merged_into_id' => $removed->id]);
    $loser->delete();
    $removed->delete();

    app(Deduplicator::class)->merge($winner, $loser);

    expect($loser->fresh()->merged_into_id)->toBe($removed->id);

    $other = Media::factory()->create();

    expect(fn () => app(Deduplicator::class)->merge($removed, $other))->toThrow(RuntimeException::class)
        ->and($other->fresh()->trashed())->toBeFalse();
});

test('merge keeps the winner\'s primary provider and only fills a missing one from the loser', function () {
    [$anilist, $mangadex] = ExternalSource::factory()->count(2)->create();
    [$winner, $loser, $bare, $bareLoser] = [
        Media::factory()->create(['primary_external_source_id' => $anilist->id]),
        Media::factory()->create(['primary_external_source_id' => $mangadex->id]),
        Media::factory()->create(),
        Media::factory()->create(['primary_external_source_id' => $mangadex->id]),
    ];

    app(Deduplicator::class)->merge($winner, $loser);
    app(Deduplicator::class)->merge($bare, $bareLoser);

    expect($winner->fresh()->primary_external_source_id)->toBe($anilist->id)->and($bare->fresh()->primary_external_source_id)->toBe($mangadex->id);
});

test('same family, a near-identical title and a year within a year but different creators is a review candidate, never an auto merge (M3)', function () {
    $existing = Media::factory()->create(['title' => 'Monster Hunter', 'publication_year' => 2010]);
    $existing->creators()->attach(Creator::factory()->create(['name' => 'Capcom Staff']), ['role' => 'author', 'position' => 0]);
    $incoming = normalized(title: 'Monster Hunter', year: 2011, creators: ['Somebody Else']);

    $candidates = app(Deduplicator::class)->candidates($incoming);

    expect(app(Deduplicator::class)->match($incoming))->toBeNull()
        ->and($candidates)->toHaveCount(1)->and($candidates->first()['media']->is($existing))->toBeTrue()->and($candidates->first()['score'])->toBe(0.8);
});

test('slugs merged into a media that is merged again follow to the final winner', function () {
    [$final, $middle, $first] = Media::factory()->count(3)->create();

    app(Deduplicator::class)->merge($middle, $first);
    app(Deduplicator::class)->merge($final, $middle);

    expect(Media::resolveSlug($first->slug)?->is($final))->toBeTrue()
        ->and(Media::resolveSlug($middle->slug)?->is($final))->toBeTrue()
        ->and(Media::resolveSlug($final->slug)?->is($final))->toBeTrue()
        ->and(Media::resolveSlug('nope'))->toBeNull();
});

test('a plainly deleted slug resolves to nothing', function () {
    $media = Media::factory()->create();
    $media->delete();

    expect(Media::resolveSlug($media->slug))->toBeNull();
});

test('merge brings the loser\'s classes over: the winner\'s row wins a clash, never a second live primary, and the limit holds', function () {
    $this->seed(DeweySeeder::class);
    [$winner, $loser] = Media::factory()->count(2)->create();
    classified($winner, '813');
    classified($winner, '500', type: 'secondary');
    classified($loser, '823');
    classified($loser, '500', type: 'secondary', status: 'pending');
    classified($loser, '600', type: 'secondary');
    classified($loser, '700', type: 'secondary');
    $winnersOwn = $winner->deweyClasses()->where('dewey_class_id', dewey('500')->id)->value('id');

    app(Deduplicator::class)->merge($winner, $loser);

    $moved = $winner->deweyClasses()->with('deweyClass')->get()->mapWithKeys(fn (MediaDeweyClass $row) => [$row->deweyClass->code => $row->classification_type]);
    expect($moved->all())->toEqual(['813' => ClassificationType::Primary, '500' => ClassificationType::Secondary, '823' => ClassificationType::Secondary, '600' => ClassificationType::Secondary])
        ->and($winner->deweyClasses()->where('dewey_class_id', dewey('500')->id)->value('id'))->toBe($winnersOwn)
        ->and($winner->deweyClasses()->where('dewey_class_id', dewey('500')->id)->value('review_status'))->toBe(ReviewStatus::Accepted)
        ->and($loser->deweyClasses()->count())->toBe(0);
});

test('merge hands the loser\'s primary to a winner without one and keeps its rejections', function () {
    $this->seed(DeweySeeder::class);
    [$winner, $loser] = Media::factory()->count(2)->create();
    classified($loser, '813');
    classified($loser, '823', status: 'rejected');

    app(Deduplicator::class)->merge($winner, $loser);

    expect($winner->primaryDewey->deweyClass->code)->toBe('813')
        ->and($winner->deweyClasses()->where('dewey_class_id', dewey('823')->id)->value('review_status'))->toBe(ReviewStatus::Rejected);
});

test('merging refreshes the cached class counts', function () {
    $this->seed(DeweySeeder::class);
    [$winner, $loser] = Media::factory()->count(2)->create();
    classified($winner, '823');
    classified($loser, '813');
    $counts = app(DeweyCounts::class);
    expect($counts->forClass(dewey('813')))->toBe(1)->and($counts->forClass(dewey('800')))->toBe(2);

    app(Deduplicator::class)->merge($winner, $loser);

    expect($counts->forClass(dewey('813')))->toBe(0)->and($counts->forClass(dewey('800')))->toBe(1);
});

test('an identical title in the same year merges even when the creator spelling differs or is missing', function () {
    $existing = Media::factory()->manga()->create(['title' => 'One Piece', 'publication_year' => 1997]);
    $existing->creators()->attach(Creator::factory()->create(['name' => 'Eiichirou Oda']), ['role' => 'author', 'position' => 0]);

    expect(app(Deduplicator::class)->match(normalized(title: 'One Piece', type: 'manga', year: 1997, creators: ['Oda Eiichirou']))?->is($existing))->toBeTrue()
        ->and(app(Deduplicator::class)->match(normalized(title: 'one piece!', type: 'manga', year: 1997))?->is($existing))->toBeTrue();
});

test('an identical title without the same year or without a year is only a review candidate', function () {
    Media::factory()->manga()->create(['title' => 'One Piece', 'publication_year' => 1997]);

    foreach ([1998, 2010, null] as $year) {
        expect(app(Deduplicator::class)->match(normalized(title: 'One Piece', type: 'manga', year: $year)))->toBeNull()
            ->and(app(Deduplicator::class)->candidates(normalized(title: 'One Piece', type: 'manga', year: $year)))->toHaveCount(1);
    }
});

/**
 * A stored manga with its creators and alternative titles.
 *
 * @param  list<string>  $creators
 * @param  list<string>  $alternativeTitles
 */
function storedManga(string $title, ?int $year, array $creators = [], array $alternativeTitles = []): Media
{
    $media = Media::factory()->manga()->create(['title' => $title, 'publication_year' => $year, 'alternative_titles' => $alternativeTitles]);

    foreach ($creators as $position => $name) {
        $media->creators()->attach(Creator::factory()->create(['name' => $name]), ['role' => 'author', 'position' => $position]);
    }

    return $media;
}

test('a romanised title finds the stored work that lists it as an alternative title, for review only', function () {
    $diaries = storedManga('The Apothecary Diaries', 2017, ['Hyuuga Natsu', 'Nekokurage'], ['Kusuriya no Hitorigoto', 'Les carnets de l\'apothicaire']);
    $incoming = normalized(title: 'Kusuriya no Hitorigoto', type: 'manga', year: 2017);

    $candidates = app(Deduplicator::class)->candidates($incoming);

    expect($candidates)->toHaveCount(1)
        ->and($candidates->first()['media']->is($diaries))->toBeTrue()
        ->and($candidates->first()['score'])->toBe(1.0)
        ->and(app(Deduplicator::class)->match($incoming))->toBeNull();
});

test('an incoming alternative title finds the stored work filed under it', function () {
    $hagane = storedManga('Hagane no Renkinjutsushi', 2001);
    $incoming = new NormalizedMedia(title: 'Fullmetal Alchemist', mediaTypeSlug: 'manga', alternativeTitles: ['Hagane no Renkinjutsushi', '鋼の錬金術師'], credits: ['Hiromu Arakawa'], publicationYear: 2001);

    $candidates = app(Deduplicator::class)->candidates($incoming);

    expect($candidates)->toHaveCount(1)
        ->and($candidates->first()['media']->is($hagane))->toBeTrue()
        ->and(app(Deduplicator::class)->match($incoming))->toBeNull();
});

test('two works that only share an alternative title are not paired', function () {
    storedManga('Fullmetal Alchemist', 2001, alternativeTitles: ['FMA', 'Hagane no Renkinjutsushi']);
    $incoming = new NormalizedMedia(title: 'Full Moon Academy', mediaTypeSlug: 'manga', alternativeTitles: ['FMA']);

    expect(app(Deduplicator::class)->candidates($incoming))->toBeEmpty();
});

test('the same title by different creators scores below the same title with no creator to compare', function () {
    storedManga('Othello', 2001, ['Satomi Ikezawa']);
    storedManga('Clover', 1997, ['CLAMP']);
    $deduplicator = app(Deduplicator::class);

    $othello = $deduplicator->candidates(normalized(title: 'Othello', type: 'manga', year: 2002, creators: ['Toui Hasumi']));
    $unknown = $deduplicator->candidates(normalized(title: 'Othello', type: 'manga', year: 2002));

    expect($othello)->toHaveCount(1)->and($othello->first()['score'])->toBe(0.8)
        ->and($unknown->first()['score'])->toBe(1.0)
        ->and($deduplicator->candidates(normalized(title: 'Clover', type: 'manga', year: 1997, creators: ['Toriko Chiya']))->first()['score'])->toBe(0.8);
});

test('an identical title in the same year does not merge when the creators differ', function () {
    storedManga('Clover', 1997, ['CLAMP']);
    $incoming = normalized(title: 'Clover', type: 'manga', year: 1997, creators: ['Toriko Chiya']);

    expect(app(Deduplicator::class)->match($incoming))->toBeNull()
        ->and(app(Deduplicator::class)->candidates($incoming))->toHaveCount(1);
});

test('a near identical title by different creators stays in review at the floor, below the same pair without creators', function () {
    storedManga('Monster Hunter Stories', 2016, ['Capcom Staff']);
    $deduplicator = app(Deduplicator::class);

    $conflicting = $deduplicator->candidates(normalized(title: 'Monster Hunter Stories 2', type: 'manga', creators: ['Somebody Else']));
    $uncredited = $deduplicator->candidates(normalized(title: 'Monster Hunter Stories 2', type: 'manga'));

    expect($conflicting)->toHaveCount(1)->and($conflicting->first()['score'])->toBe(0.75)
        ->and($uncredited->first()['score'])->toBeGreaterThan(0.75);
});

test('an identical title in the same year with creators written in different scripts is kept for review, not merged', function () {
    $titan = storedManga('Shingeki no Kyojin', 2009, ['諫山創']);
    $incoming = normalized(title: 'Shingeki no Kyojin', type: 'manga', year: 2009, creators: ['Hajime Isayama']);

    $candidates = app(Deduplicator::class)->candidates($incoming);

    expect(app(Deduplicator::class)->match($incoming))->toBeNull()
        ->and($candidates)->toHaveCount(1)->and($candidates->first()['media']->is($titan))->toBeTrue()->and($candidates->first()['score'])->toBe(0.8);
});

test('the work that shares a creator is found even when many works share its title', function () {
    foreach (range(1, 30) as $n) {
        storedManga('Monster', 2000 + $n, ["Other Author {$n}"]);
    }
    $urasawa = storedManga('Monster', 1994, ['Naoki Urasawa']);

    expect(app(Deduplicator::class)->match(normalized(title: 'Monster', type: 'manga', year: 1994, creators: ['Naoki Urasawa']))?->is($urasawa))->toBeTrue();
});

test('a translation matching only an alternative title of a manhwa does not take its form', function () {
    storedManhwa()->update(['alternative_titles' => ['Na Honjaman Level Up']]);
    $translation = new NormalizedMedia(title: 'Na Honjaman Level Up', mediaTypeSlug: 'comic-book', credits: ['Chugong'], publicationYear: 2021);

    expect(app(Deduplicator::class)->originalForm($translation))->toBeNull()
        ->and(app(Deduplicator::class)->candidates($translation))->toHaveCount(1);
});

test('a volume is not merged into its series, even when the series lists the volume title', function () {
    $series = Media::factory()->ofType('graphic-novel')->create(['title' => 'Heartstopper', 'publication_year' => 2019]);
    $series->creators()->attach(Creator::factory()->create(['name' => 'Alice Oseman']), ['role' => 'author', 'position' => 0]);
    $volume = normalized(title: 'Heartstopper: Volume One', type: 'graphic-novel', year: 2019, creators: ['Alice Oseman']);

    expect(app(Deduplicator::class)->match($volume))->toBeNull();

    $series->update(['alternative_titles' => ['Heartstopper: Volume One']]);

    expect(app(Deduplicator::class)->match($volume))->toBeNull()
        ->and(app(Deduplicator::class)->candidates($volume))->toHaveCount(1);
});

test('a rescan pairs existing works by their alternative titles, re-scores pairs by creators and merges nothing', function () {
    $diaries = storedManga('The Apothecary Diaries', 2017, ['Hyuuga Natsu'], ['Kusuriya no Hitorigoto']);
    $kusuriya = storedManga('Kusuriya no Hitorigoto', 2017);
    [$hasumi, $ikezawa] = [storedManga('Othello', 2002, ['Toui Hasumi']), storedManga('Othello', 2001, ['Satomi Ikezawa'])];
    [$clamp, $chiya] = [storedManga('Clover', 1997, ['CLAMP']), storedManga('Clover', 1997, ['Toriko Chiya'])];
    [$berserk, $monster] = [storedManga('Berserk', 1989), storedManga('Monster', 1994)];
    $othello = DuplicateCandidate::factory()->create(['media_id' => $hasumi->id, 'duplicate_media_id' => $ikezawa->id, 'score' => 1.0]);
    $clover = DuplicateCandidate::factory()->create(['media_id' => $clamp->id, 'duplicate_media_id' => $chiya->id, 'score' => 1.0, 'status' => DuplicateStatus::Dismissed]);
    $stale = DuplicateCandidate::factory()->create(['media_id' => $berserk->id, 'duplicate_media_id' => $monster->id, 'score' => 0.8]);
    $this->travel(2)->seconds();

    $this->artisan('bookflix:rescan-duplicates')->assertSuccessful();

    $pair = DuplicateCandidate::query()->where(['media_id' => $diaries->id, 'duplicate_media_id' => $kusuriya->id])->first();

    expect($pair?->status)->toBe(DuplicateStatus::Pending)->and($pair?->score)->toBe(1.0)
        ->and($othello->fresh()->score)->toBe(0.8)->and($othello->fresh()->status)->toBe(DuplicateStatus::Pending)
        ->and($clover->fresh()->status)->toBe(DuplicateStatus::Dismissed)->and($clover->fresh()->score)->toBe(0.8)
        ->and($stale->fresh())->toBeNull()
        ->and(Media::onlyTrashed()->count())->toBe(0);
});
