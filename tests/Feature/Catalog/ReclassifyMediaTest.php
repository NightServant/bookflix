<?php

use App\Enums\AgeRating;
use App\Enums\WarningStatus;
use App\Jobs\ClassifyMedia;
use App\Models\ContentWarning;
use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaExternalId;
use App\Models\Publisher;
use App\Models\Tag;
use Database\Seeders\MediaTypeSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->seed([MediaTypeSeeder::class, TaxonomySeeder::class]);
    Bus::fake([ClassifyMedia::class]);
});

/**
 * A media of the given type that came from a provider.
 *
 * @param  array<string, mixed>  $attributes
 */
function sourced(string $provider, string $type, array $attributes = []): Media
{
    $media = Media::factory()->ofType($type)->create($attributes);
    $source = ExternalSource::firstOrCreate(['key' => $provider], ['name' => $provider, 'base_url' => "https://{$provider}.test", 'attribution' => '', 'enabled' => false]);
    MediaExternalId::factory()->create(['media_id' => $media->id, 'external_source_id' => $source->id]);
    $media->update(['primary_external_source_id' => $source->id]);

    return $media;
}

test('a novel stored as a comic book is reclassified, and its Dewey class is queued again', function () {
    $wrong = sourced('comicvine', 'comic-book', ['title' => 'Batman: The Novel', 'description' => 'A prose novel.']);
    $right = sourced('comicvine', 'comic-book', ['title' => 'Batman']);
    $wrong->publishers()->attach(Publisher::factory()->create(['name' => 'DC Comics']));

    $this->artisan('bookflix:reclassify')
        ->expectsOutputToContain('Looked at 2 media; 1 changed.')
        ->expectsOutputToContain('Queued Dewey classification again for 1 media whose type changed.')
        ->assertSuccessful();

    expect($wrong->fresh()->mediaType->slug)->toBe('novel')
        ->and($right->fresh()->mediaType->slug)->toBe('comic-book');
    Bus::assertDispatched(ClassifyMedia::class, fn (ClassifyMedia $job) => $job->mediaId === $wrong->id);
    Bus::assertDispatchedTimes(ClassifyMedia::class, 1);
});

test('the summary counts each old type to new type', function () {
    sourced('comicvine', 'comic-book', ['title' => 'Superman: The Novel']);
    sourced('comicvine', 'comic-book', ['title' => 'Flash: The Novel']);
    sourced('openlibrary', 'graphic-novel', ['title' => 'Sword Art Online (Light Novel)']);

    $this->artisan('bookflix:reclassify')->assertSuccessful()
        ->expectsTable(['Old type', 'New type', 'Media'], [['comic-book', 'novel', 2], ['graphic-novel', 'light-novel', 1]]);
});

test('a dry run reports and writes nothing', function () {
    $media = sourced('comicvine', 'comic-book', ['title' => 'Batman: The Novel']);
    $media->tags()->attach(Tag::factory()->create(['name' => 'Gore', 'slug' => 'gore']));

    $this->artisan('bookflix:reclassify', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

    expect($media->fresh()->mediaType->slug)->toBe('comic-book')
        ->and($media->tags()->count())->toBe(1)
        ->and($media->warnings()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

test('admin locks are respected', function () {
    $media = sourced('comicvine', 'comic-book', ['title' => 'Batman: The Novel', 'locked_fields' => ['media_type_id', 'tags', 'genres', 'content_warnings', 'demographics', 'age_rating']]);
    $media->tags()->attach(Tag::factory()->count(3)->sequence(['name' => 'Gore'], ['name' => 'Shounen'], ['name' => 'Ecchi'])->create());

    $this->artisan('bookflix:reclassify')->assertSuccessful();

    $media->refresh();

    expect($media->mediaType->slug)->toBe('comic-book')->and($media->tags()->count())->toBe(3)
        ->and($media->warnings()->count())->toBe(0)->and($media->demographics()->count())->toBe(0)->and($media->age_rating)->toBe(AgeRating::Unknown);
});

test('media whose provider names the format keep their type', function () {
    $manga = sourced('anilist', 'manga', ['title' => 'Overlord (Light Novel)', 'description' => 'Overlord is a light novel series.']);

    $this->artisan('bookflix:reclassify')->assertSuccessful();

    expect($manga->fresh()->mediaType->slug)->toBe('manga');
    Bus::assertNothingDispatched();
});

test('tags are mapped to demographics, genres, tropes and warnings with the importers mapping, and folded into them', function () {
    $media = sourced('anilist', 'manga', ['title' => 'Slime Story']);
    $media->tags()->attach(collect(['Shounen', 'Gore', 'Isekai', 'Reincarnation', 'Ecchi', 'Male Protagonist'])->map(fn (string $name) => Tag::factory()->create(['name' => $name, 'slug' => Str::slug($name)])));

    $this->artisan('bookflix:reclassify')->assertSuccessful();

    $media->refresh()->load(['genres', 'demographics', 'tropes', 'warnings', 'tags']);

    expect($media->demographics->pluck('slug')->all())->toBe(['shonen'])
        ->and($media->genres->pluck('slug')->all())->toEqualCanonicalizing(['isekai', 'reincarnation-fantasy'])
        ->and($media->tropes->pluck('slug')->all())->toBe(['reincarnation'])
        ->and($media->warnings->pluck('slug')->all())->toEqualCanonicalizing(['gore', 'sexual-content'])
        ->and($media->warnings->every(fn ($warning) => $warning->pivot->status === WarningStatus::Present))->toBeTrue()
        ->and($media->age_rating)->toBe(AgeRating::MatureTeen)
        ->and($media->tags->pluck('name')->all())->toEqualCanonicalizing(['Ecchi', 'Male Protagonist'])
        ->and($media->search_text)->toContain('shonen')->toContain('isekai');
});

test('re-running changes nothing more', function () {
    $media = sourced('anilist', 'manga');
    $media->tags()->attach(Tag::factory()->create(['name' => 'Gore']));
    $this->artisan('bookflix:reclassify')->assertSuccessful();
    $state = fn () => [$media->genres()->count(), $media->warnings()->count(), $media->tags()->count(), $media->fresh()->updated_at->toIso8601String()];
    $before = $state();
    $this->travel(2)->minutes();

    $this->artisan('bookflix:reclassify')->expectsOutputToContain('0 changed')->assertSuccessful();

    expect($state())->toBe($before);
});

test('a warning verified absent is disputed, not erased, and an unknown one stays unknown', function () {
    $media = sourced('anilist', 'manga');
    $media->tags()->attach(Tag::factory()->count(2)->sequence(['name' => 'Gore'], ['name' => 'Torture'])->create());
    $media->warnings()->attach(ContentWarning::where('slug', 'gore')->first(), ['status' => 'absent', 'source' => 'admin']);

    $this->artisan('bookflix:reclassify')->assertSuccessful();

    $statuses = $media->warnings()->get()->mapWithKeys(fn ($warning) => [$warning->slug => $warning->pivot->status])->all();

    expect($statuses)->toBe(['torture' => WarningStatus::Present, 'gore' => WarningStatus::Disputed])
        ->and($media->warnings()->where('slug', 'nudity')->exists())->toBeFalse();
});

test('a media labelled with a term for sexual content involving minors is removed', function () {
    $bad = sourced('mangadex', 'manga', ['title' => 'Bad']);
    $bad->tags()->attach(Tag::factory()->create(['name' => 'Loli']));
    $good = sourced('mangadex', 'manga', ['title' => 'Good']);

    $this->artisan('bookflix:reclassify')->expectsOutputToContain('1 media labelled')->assertSuccessful();

    expect(Media::find($bad->id))->toBeNull()->and(Media::withTrashed()->find($bad->id)->trashed())->toBeTrue()
        ->and(Media::find($good->id))->not->toBeNull();
});

test('only the media of the given source are looked at', function () {
    $comic = sourced('comicvine', 'comic-book', ['title' => 'A: The Novel']);
    $book = sourced('openlibrary', 'graphic-novel', ['title' => 'B: The Novel']);

    $this->artisan('bookflix:reclassify', ['--source' => 'comicvine'])->assertSuccessful();

    expect($comic->fresh()->mediaType->slug)->toBe('novel')->and($book->fresh()->mediaType->slug)->toBe('graphic-novel');
});

test('a source that does not exist is refused', function () {
    $this->artisan('bookflix:reclassify', ['--source' => 'nope'])->assertFailed();
});

test('a type the classifier is not sure enough of is left alone', function () {
    $media = sourced('openlibrary', 'graphic-novel', ['title' => 'Unclear']);

    $this->artisan('bookflix:reclassify', ['--min-confidence' => '0.99'])->assertSuccessful();

    expect($media->fresh()->mediaType->slug)->toBe('graphic-novel');
});

test('resync clears the payload hashes of one source so its next full sync re-imports everything', function () {
    $comic = sourced('comicvine', 'comic-book');
    $other = sourced('openlibrary', 'novel');
    $hash = $other->externalIds()->value('payload_hash');

    $this->artisan('bookflix:reclassify', ['--resync' => true])->assertFailed();
    $this->artisan('bookflix:reclassify', ['--resync' => true, '--source' => 'comicvine', '--dry-run' => true])->expectsOutputToContain('Would clear')->assertSuccessful();

    expect($comic->externalIds()->value('payload_hash'))->not->toBe(str_repeat('0', 64));

    $this->artisan('bookflix:reclassify', ['--resync' => true, '--source' => 'comicvine'])->expectsOutputToContain('bookflix:sync comicvine --full')->assertSuccessful();

    expect($comic->externalIds()->value('payload_hash'))->toBe(str_repeat('0', 64))->and($other->externalIds()->value('payload_hash'))->toBe($hash);
});
