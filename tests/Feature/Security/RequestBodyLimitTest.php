<?php

use App\Models\ContentSource;
use App\Models\Media;
use App\Models\User;

/*
 * The JSON and beacon POST routes carry small bodies (a reading place, a few ids), so a body past the cap is refused with 413 before anything reads it.
 * The cap is 8 KB: the largest legitimate body is the impressions beacon, 100 ids of at most 19 digits (about 2.1 KB), and a reading place is
 * about 2.5 KB at most (a CFI of 2048 characters plus a chapter name and counters).
 */

function bodyOf(int $bytes): string
{
    return json_encode(['padding' => str_repeat('a', $bytes)]);
}

test('an oversized body is refused with 413 on every capped route', function (string $method, string $route, array $params) {
    $user = User::factory()->create();
    $this->actingAs($user);
    $params = array_map(fn ($param) => $param === 'OWNED' ? ContentSource::factory()->userOwned($user)->ready()->create()->id : $param, $params);

    $this->call($method, route($route, $params), content: bodyOf(9 * 1024), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'])
        ->assertStatus(413);
})->with([
    ['POST', 'reader.progress', ['source' => 'OWNED']],
    ['POST', 'recommendations.click', []],
    ['POST', 'recommendations.impressions', []],
    ['POST', 'recommendations.dismiss', ['recommendation' => 1]],
    ['DELETE', 'recommendations.undo', ['recommendation' => 1]],
    ['POST', 'library.store', []],
]);

test('the cap uses the real body when the Content-Length header is missing or understated', function () {
    $this->actingAs(User::factory()->create());

    $this->call('POST', route('recommendations.click'), content: bodyOf(9 * 1024), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => '10'])
        ->assertStatus(413);
});

test('a normal body passes the cap', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $largest = json_encode(['ids' => array_fill(0, 100, 9223372036854775807)]);
    expect(strlen($largest))->toBeLessThan(8 * 1024);

    $this->call('POST', route('recommendations.impressions'), content: $largest, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'])
        ->assertNoContent();

    $this->post(route('library.store'), ['media_id' => Media::factory()->create()->id])->assertRedirect();
});
