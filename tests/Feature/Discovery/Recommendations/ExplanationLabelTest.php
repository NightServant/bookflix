<?php

use App\Discovery\Recommendations\Explanation;
use App\Enums\ReasonType;

test('each reason type renders its template with titles', function (ReasonType $type, array $args, ?string $expected) {
    expect((new Explanation($type, ...$args))->label())->toBe($expected);
})->with([
    [ReasonType::BecauseYouRead, ['reasonTitle' => 'Dune'], 'Because you read Dune'],
    [ReasonType::SimilarThemes, ['reasonTitle' => 'Dune'], 'Similar themes to Dune'],
    [ReasonType::PopularWithSimilarReaders, ['reasonTitle' => 'Dune'], 'Popular with readers who enjoyed Dune'],
    [ReasonType::MatchesInterest, ['genre' => 'Fantasy'], 'Matches your interest in Fantasy'],
    [ReasonType::Trending, [], 'Trending now'],
    [ReasonType::Editorial, [], 'Picked by our editors'],
    [ReasonType::NewRelease, [], 'New release'],
    [ReasonType::Exploration, [], 'Something different for you'],
]);

test('a missing title or genre yields no label', function (ReasonType $type) {
    expect((new Explanation($type))->label())->toBeNull();
})->with([ReasonType::BecauseYouRead, ReasonType::SimilarThemes, ReasonType::PopularWithSimilarReaders, ReasonType::MatchesInterest]);

test('an empty title or genre yields no label either', function () {
    expect((new Explanation(ReasonType::BecauseYouRead, ''))->label())->toBeNull()
        ->and((new Explanation(ReasonType::MatchesInterest, null, ''))->label())->toBeNull();
});

test('a template that needs a title ignores a genre and the other way round', function () {
    expect((new Explanation(ReasonType::BecauseYouRead, null, 'Fantasy'))->label())->toBeNull()
        ->and((new Explanation(ReasonType::MatchesInterest, 'Dune'))->label())->toBeNull();
});

test('labels go through the translator', function () {
    app('translator')->addLines(['*.Trending now' => 'En vogue', '*.Because you read :title' => 'Parce que vous avez lu :title'], 'en');

    expect((new Explanation(ReasonType::Trending))->label())->toBe('En vogue')
        ->and((new Explanation(ReasonType::BecauseYouRead, 'Dune'))->label())->toBe('Parce que vous avez lu Dune');
});
