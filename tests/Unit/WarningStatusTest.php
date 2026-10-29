<?php

use App\Enums\WarningStatus;

test('only present and disputed warnings are shown', function () {
    expect(WarningStatus::shownValues())->toBe(['present', 'disputed']);
});

test('reconciling statuses never turns unknown into absent', function (WarningStatus $current, WarningStatus $incoming, WarningStatus $expected) {
    expect($current->reconcile($incoming))->toBe($expected);
})->with([
    'a repeat changes nothing' => [WarningStatus::Present, WarningStatus::Present, WarningStatus::Present],
    'unknown gives way to present' => [WarningStatus::Unknown, WarningStatus::Present, WarningStatus::Present],
    'unknown gives way to verified absent' => [WarningStatus::Unknown, WarningStatus::Absent, WarningStatus::Absent],
    'silence never erases a present warning' => [WarningStatus::Present, WarningStatus::Unknown, WarningStatus::Present],
    'silence never erases an absent one' => [WarningStatus::Absent, WarningStatus::Unknown, WarningStatus::Absent],
    'present against absent is disputed' => [WarningStatus::Present, WarningStatus::Absent, WarningStatus::Disputed],
    'absent against present is disputed' => [WarningStatus::Absent, WarningStatus::Present, WarningStatus::Disputed],
    'disputed stays disputed' => [WarningStatus::Disputed, WarningStatus::Present, WarningStatus::Disputed],
]);
