<?php

use App\Classification\Dewey\DeweyHierarchy;
use App\Models\DeweyClass;
use Database\Seeders\DeweySeeder;

beforeEach(fn () => $this->seed(DeweySeeder::class));

test('there are ten roots and 800 has 810 as a child', function () {
    $h = app(DeweyHierarchy::class);
    expect($h->roots())->toHaveCount(10)
        ->and($h->children($h->findByCode('800'))->pluck('code'))->toContain('810');
});

test('ancestors of 813 are 800 then 810', function () {
    $h = app(DeweyHierarchy::class);
    expect($h->ancestors($h->findByCode('813'))->pluck('code')->all())->toBe(['800', '810']);
});

test('path and level are computed', function () {
    $c = DeweyClass::firstWhere('code', '813');
    expect($c->path)->toBe('800/810/813')->and($c->level)->toBe(3);
});

test('subtree query returns self and descendants only', function () {
    $ids = app(DeweyHierarchy::class)->subtreeIds(DeweyClass::firstWhere('code', '810'));
    $codes = DeweyClass::whereIn('id', $ids)->pluck('code');
    expect($codes)->toContain('810', '813')->not->toContain('800', '823');
});

test('similarity is lowest common ancestor depth over the larger depth', function () {
    $h = app(DeweyHierarchy::class);
    expect($h->similarity($h->findByCode('813'), $h->findByCode('823')))->toEqualWithDelta(1 / 3, 0.0001)
        ->and($h->similarity($h->findByCode('813'), $h->findByCode('813')))->toBe(1.0)
        ->and($h->similarity($h->findByCode('813'), $h->findByCode('510')))->toBe(0.0);
});

test('seeding twice does not duplicate classes', function () {
    $count = DeweyClass::count();
    $this->seed(DeweySeeder::class);
    expect(DeweyClass::count())->toBe($count);
});

test('810 has exactly 811 to 818 as children, in order', function () {
    $h = app(DeweyHierarchy::class);
    expect($h->children($h->findByCode('810'))->pluck('code')->all())
        ->toBe(['811', '812', '813', '814', '815', '816', '817', '818']);
});

test('the comics subtree exists under 740', function () {
    expect(DeweyClass::firstWhere('code', '741.55')->path)->toBe('700/740/741.5/741.55');
});
