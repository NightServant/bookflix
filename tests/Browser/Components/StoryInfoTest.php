<?php

use App\Models\Media;

test('story info passes the shared acceptance checks and draws dots between parts only', function () {
    $media = Media::factory()->manga()->create(['publication_year' => 1989, 'page_count' => 224])->fresh(['mediaType', 'publicPrimaryDewey.deweyClass']);
    $path = componentPage('<x-story-info :media="$media" />', ['media' => $media]);

    assertComponentAcceptance($path, function ($page) {
        $page->assertScript('getComputedStyle(document.querySelector("main li"), "::after").content', '"·"')
            ->assertScript('getComputedStyle(document.querySelector("main li:last-child"), "::after").content', 'none');
    });
});
