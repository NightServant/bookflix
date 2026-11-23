<?php

use App\Models\Media;
use App\Models\User;

/*
 * The staff pick editor in a real browser: Escape in the story search clears it rather than closing the editor and losing the draft.
 */

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
    Media::factory()->novel()->create(['title' => 'Sir Nigel']);
});

test('Escape in a filled story search clears it and keeps the editor open; Escape again closes it', function () {
    $search = 'input[aria-label="Add a story by title or author"]';
    $page = visit('/admin/staff-picks', ['viewport' => ['width' => 1440, 'height' => 900]])->wait(0.6);

    $page->click('New staff pick')->wait(0.4)
        ->type('input[wire\\:model="name"]', 'Classic adventures')
        ->type($search, 'Sir Nigel')->wait(1)
        ->assertSee('Sir Nigel')
        ->keys($search, 'Escape')->wait(0.6)
        ->assertValue($search, '')
        ->assertValue('input[wire\\:model="name"]', 'Classic adventures')
        ->assertVisible($search)
        ->keys($search, 'Escape')->wait(0.6)
        ->assertMissing($search)
        ->assertNoJavaScriptErrors();
});
