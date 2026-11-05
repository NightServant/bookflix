<?php

use App\Models\ExternalSource;
use App\Models\Media;
use App\Models\MediaType;
use App\Models\Tag;
use Database\Seeders\MediaTypeSeeder;

function hardcoverSource(): ExternalSource
{
    return ExternalSource::where('key', 'hardcover')->first() ?? ExternalSource::factory()->create(['key' => 'hardcover']);
}

function migrationFile(string $name): object
{
    return require database_path("migrations/{$name}.php");
}

test('the Bluest Eye from Hardcover becomes a locked novel and loses its comic types', function () {
    $story = Media::factory()->ofType('comic-book')->create([
        'title' => 'The Bluest Eye',
        'primary_external_source_id' => hardcoverSource()->id,
        'locked_fields' => ['title'],
    ]);
    $graphic = MediaType::firstOrCreate(['slug' => 'graphic-novel'], MediaTypeSeeder::TYPES['graphic-novel']);
    $story->additionalTypes()->attach($graphic);
    MediaType::firstOrCreate(['slug' => 'novel'], MediaTypeSeeder::TYPES['novel']);

    migrationFile('2026_11_04_000001_file_the_bluest_eye_as_a_novel')->up();

    $story->refresh();
    expect($story->mediaType->slug)->toBe('novel')
        ->and($story->locked_fields)->toBe(['title', 'media_type_id'])
        ->and($story->additionalTypes()->count())->toBe(0);
});

test('the type fix keeps an additional type outside the comic family and does not lock twice', function () {
    $story = Media::factory()->ofType('comic-book')->create([
        'title' => 'The Bluest Eye',
        'primary_external_source_id' => hardcoverSource()->id,
        'locked_fields' => ['media_type_id'],
    ]);
    $story->additionalTypes()->attach(MediaType::firstOrCreate(['slug' => 'nonfiction'], MediaTypeSeeder::TYPES['nonfiction']));
    MediaType::firstOrCreate(['slug' => 'novel'], MediaTypeSeeder::TYPES['novel']);

    migrationFile('2026_11_04_000001_file_the_bluest_eye_as_a_novel')->up();

    expect($story->fresh()->locked_fields)->toBe(['media_type_id'])
        ->and($story->additionalTypes()->pluck('slug')->all())->toBe(['nonfiction']);
});

test('the type fix leaves every other row alone, and a database without the row as it is', function () {
    $otherSource = Media::factory()->ofType('comic-book')->create(['title' => 'The Bluest Eye', 'primary_external_source_id' => ExternalSource::factory()->create(['key' => 'openlibrary'])->id]);
    $otherTitle = Media::factory()->ofType('comic-book')->create(['title' => 'Song of Solomon', 'primary_external_source_id' => hardcoverSource()->id]);
    MediaType::firstOrCreate(['slug' => 'novel'], MediaTypeSeeder::TYPES['novel']);

    migrationFile('2026_11_04_000001_file_the_bluest_eye_as_a_novel')->up();

    expect($otherSource->fresh()->mediaType->slug)->toBe('comic-book')->and($otherSource->fresh()->locked_fields)->toBe([])
        ->and($otherTitle->fresh()->mediaType->slug)->toBe('comic-book');
});

test('works carrying a term the hard rule names are soft-deleted, as a fresh import would refuse them', function () {
    $warned = Media::factory()->create(['title' => 'Warned', 'content_warnings' => ['Violence', 'Pedophilia']]);
    $tagged = Media::factory()->create(['title' => 'Tagged']);
    $tagged->tags()->attach(Tag::create(['name' => 'Loli', 'slug' => 'loli']));
    $clean = Media::factory()->create(['title' => 'Clean', 'content_warnings' => ['Violence', 'Child death']]);
    $named = Media::factory()->create(['title' => 'Named']);
    $named->tags()->attach(Tag::create(['name' => 'Shotaro Kaneda', 'slug' => 'shotaro-kaneda']));

    migrationFile('2026_11_04_000002_remove_media_a_fresh_import_would_refuse')->up();

    expect(Media::pluck('title')->sort()->values()->all())->toBe(['Clean', 'Named'])
        ->and(Media::onlyTrashed()->pluck('title')->sort()->values()->all())->toBe(['Tagged', 'Warned'])
        ->and(Media::withTrashed()->count())->toBe(4);
});

test('the removal does nothing on a catalog with no such work', function () {
    Media::factory()->count(3)->create(['content_warnings' => ['Gore']]);

    migrationFile('2026_11_04_000002_remove_media_a_fresh_import_would_refuse')->up();

    expect(Media::count())->toBe(3)->and(Media::onlyTrashed()->count())->toBe(0);
});
