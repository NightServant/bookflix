<?php

use App\Classification\Dewey\DeweyResolver;
use Database\Seeders\DeweySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => $this->seed(DeweySeeder::class));

test('the resolver finds the deepest seeded prefix', function (string $code, ?string $expected) {
    expect(app(DeweyResolver::class)->resolve($code)?->code)->toBe($expected);
})->with([
    ['813.54', '813'], ['813', '813'], ['741.5952', '741.5'], ['891.73', '891.7'],
    ['999.99', null], ['', null], ['abc', null], ['813.', null], ['  813.54 ', '813'],
    ['813.54.2', null], ['81354', null], ['813.5'.str_repeat('4', 5000), '813'], ["813\n.5", null],
]);

test('every subject keyword points at a seeded class', function () {
    $codes = collect(file(database_path('data/dewey_subject_keywords.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->skip(1)->map(fn (string $line) => str_getcsv($line, escape: '')[1])->unique();

    expect($codes)->not->toBeEmpty()
        ->and($codes->reject(fn (string $code) => app(DeweyResolver::class)->resolve($code)?->code === $code))->toBeEmpty();
});
