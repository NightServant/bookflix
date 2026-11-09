<?php

use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Enums\ReviewStatus;
use App\Models\Genre;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use Database\Seeders\DeweySeeder;

beforeEach(fn () => $this->seed(DeweySeeder::class));

function deweyRow(Media $media, string $code, array $attributes = []): MediaDeweyClass
{
    return MediaDeweyClass::factory()->for($media)->create([
        'dewey_class_id' => dewey($code)->id,
        'classification_type' => ClassificationType::Primary,
        'confidence' => 0.75,
        'source' => 'rules',
        'classification_method' => ClassificationMethod::Automatic,
        'review_status' => ReviewStatus::Accepted,
        ...$attributes,
    ]);
}

test('the cleanup deletes the old literature guesses on prose with no genres and keeps every other row', function () {
    $untagged = Media::factory()->novel()->create(['title' => 'al-Nūr al-Lāmiʿ', 'language' => 'en']);
    $guess = deweyRow($untagged, '813');
    $keyword = deweyRow($untagged, '640', ['classification_type' => ClassificationType::Secondary, 'confidence' => 0.70]);
    $pendingGuess = deweyRow(Media::factory()->ofType('nonfiction')->create(), '895.6', ['review_status' => ReviewStatus::Pending]);

    $provider = deweyRow(Media::factory()->novel()->create(), '813', ['confidence' => 0.95, 'source' => 'external_code', 'classification_method' => ClassificationMethod::External]);
    $manual = deweyRow(Media::factory()->novel()->create(), '813', ['confidence' => 1.0, 'source' => 'admin', 'classification_method' => ClassificationMethod::Manual]);
    $tagged = Media::factory()->novel()->create();
    $tagged->genres()->attach(Genre::factory()->create());
    $taggedGuess = deweyRow($tagged, '813');

    $migration = require database_path('migrations/2026_11_09_000001_remove_literature_guesses_for_prose_without_genres.php');
    $migration->up();
    $migration->up();

    expect(MediaDeweyClass::whereKey([$guess->id, $pendingGuess->id])->count())->toBe(0)
        ->and(MediaDeweyClass::pluck('id')->sort()->values()->all())->toBe(collect([$keyword->id, $provider->id, $manual->id, $taggedGuess->id])->sort()->values()->all());
});
