<?php

use App\Jobs\ClassifyMedia;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use Database\Seeders\DeweySeeder;
use Illuminate\Support\Facades\Bus;

beforeEach(fn () => $this->seed(DeweySeeder::class));

test('classify all dispatches one job per media and only-missing skips classified ones', function () {
    Bus::fake();
    classified(Media::factory()->create(), '813');
    Media::factory()->count(2)->create();

    $this->artisan('bookflix:classify-all', ['--only-missing' => true])->assertSuccessful();
    Bus::assertDispatchedTimes(ClassifyMedia::class, 2);
});

test('classify all without only-missing queues every media', function () {
    Bus::fake();
    classified(Media::factory()->create(), '813');
    Media::factory()->count(2)->create();

    $this->artisan('bookflix:classify-all')->assertSuccessful();
    Bus::assertDispatchedTimes(ClassifyMedia::class, 3);
});

test('only-missing still queues media whose primary was rejected', function () {
    Bus::fake();
    $media = Media::factory()->create();
    MediaDeweyClass::factory()->for($media)->create(['dewey_class_id' => dewey('813')->id, 'classification_type' => 'primary', 'review_status' => 'rejected']);

    $this->artisan('bookflix:classify-all', ['--only-missing' => true])->assertSuccessful();
    Bus::assertDispatchedTimes(ClassifyMedia::class, 1);
});

test('coverage reports the share of media with a public primary class', function () {
    classified(Media::factory()->count(4)->create(), '813');
    Media::factory()->create();
    $this->artisan('bookflix:classification-coverage')->expectsOutputToContain('Coverage: 80.0% (4/5)')->assertSuccessful();
});

test('coverage ignores hidden primaries and copes with an empty catalog', function () {
    $this->artisan('bookflix:classification-coverage')->expectsOutputToContain('Coverage: 0.0% (0/0)')->assertSuccessful();

    $media = Media::factory()->create();
    MediaDeweyClass::factory()->for($media)->create(['dewey_class_id' => dewey('813')->id, 'classification_type' => 'primary', 'confidence' => .6]);
    $this->artisan('bookflix:classification-coverage')->expectsOutputToContain('Coverage: 0.0% (0/1)')->assertSuccessful();
});
