<?php

use App\Classification\Dewey\DeweyValidator;
use Tests\TestCase;

uses(TestCase::class);

test('the validator keeps one primary and three secondary and drops low confidence', function () {
    $in = [cand('813', .95), cand('500', .90), cand('600', .85), cand('700', .80), cand('900', .75), cand('300', .20)];
    $out = (new DeweyValidator)->filter($in);

    expect($out)->toHaveCount(4)
        ->and($out[0]->code)->toBe('813')
        ->and(collect($out)->pluck('code'))->not->toContain('900', '300');
});

test('duplicate codes are collapsed to the highest confidence', function () {
    $out = (new DeweyValidator)->filter([cand('813', .70), cand('813', .90)]);
    expect($out)->toHaveCount(1)->and($out[0]->confidence)->toBe(.90);
});
