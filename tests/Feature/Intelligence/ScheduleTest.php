<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function scheduledMl(string $name): Event
{
    return collect(app(Schedule::class)->events())->first(fn (Event $event): bool => $event->description === $name);
}

test('the ml pipelines are scheduled at the documented times', function () {
    expect(scheduledMl('ml:popularity')->expression)->toBe('0 * * * *')
        ->and(scheduledMl('ml:item-cf')->expression)->toBe('0 3 * * *')
        ->and(scheduledMl('ml:lsa')->expression)->toBe('0 2 * * 0');
});

test('the pipelines wait for a configured ml service', function () {
    config(['services.ml.token' => null]);
    expect(scheduledMl('ml:lsa')->filtersPass(app()))->toBeFalse();

    config(['services.ml.token' => str_repeat('s', 32)]);
    expect(scheduledMl('ml:lsa')->filtersPass(app()))->toBeTrue();
});
