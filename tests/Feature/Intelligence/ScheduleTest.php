<?php

use App\Jobs\RunMlJob;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;

function scheduledMl(string $name): Event
{
    return collect(app(Schedule::class)->events())->first(fn (Event $event): bool => $event->description === $name);
}

test('the ml pipelines are scheduled at the documented times', function () {
    expect(scheduledMl('ml:popularity')->expression)->toBe('0 * * * *')
        ->and(scheduledMl('ml:item-cf')->expression)->toBe('0 3 * * *')
        ->and(scheduledMl('ml:lsa')->expression)->toBe('0 2 * * 0');
});

test('every pipeline waits for a configured ml service', function (string $name) {
    config(['services.ml.token' => null]);
    expect(scheduledMl($name)->filtersPass(app()))->toBeFalse();

    config(['services.ml.token' => str_repeat('s', 32)]);
    expect(scheduledMl($name)->filtersPass(app()))->toBeTrue();
})->with(['ml:popularity', 'ml:item-cf', 'ml:lsa']);

test('a scheduled pipeline is queued once and not again while it runs', function () {
    Bus::fake([RunMlJob::class]);

    scheduledMl('ml:lsa')->run(app());
    scheduledMl('ml:lsa')->run(app());

    Bus::assertDispatchedTimes(RunMlJob::class, 1);
});
