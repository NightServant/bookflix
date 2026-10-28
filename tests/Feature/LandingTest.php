<?php

use App\Models\Media;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * The public landing page: what a guest sees (six sections between a header and a footer), where its buttons go, what the Top 10 and
 * the hero's books are drawn from, and how few queries all of it costs. The look, the motion and the keyboard are in tests/Browser/LandingTest.php.
 */

beforeEach(fn () => Cache::flush());

/**
 * The text of every node an XPath finds, whitespace squeezed.
 *
 * @return list<string>
 */
function landingTexts(string $html, string $xpath): array
{
    return collect(iterator_to_array(htmlQuery($html, $xpath)))->map(fn (DOMNode $node): string => trim(preg_replace('/\s+/', ' ', $node->textContent)))->values()->all();
}

test('a guest sees the six sections in order, between a header and a footer, under one main landmark', function () {
    popularStories(45);

    $html = $this->get(route('landing'))->assertOk()->getContent();

    expect(landingTexts($html, '//section[@data-section]/@data-section'))->toBe(['hero', 'top-ten', 'scattered', 'together', 'faq', 'closing'])
        ->and(htmlQuery($html, '//main[@id="main"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//body/header')->length)->toBe(1)
        ->and(htmlQuery($html, '//body/footer')->length)->toBe(1)
        ->and(htmlQuery($html, '//h1')->length)->toBe(1)
        ->and(htmlQuery($html, '//body/a[@href="#main"]')->length)->toBe(1)
        ->and(landingTexts($html, '//h1'))->toBe(['Your Library. Every Story.']);
});

test('every section is named by its own heading', function () {
    popularStories(45);

    $html = $this->get(route('landing'))->getContent();

    foreach (['hero', 'top-ten', 'scattered', 'together', 'faq', 'closing'] as $section) {
        $labelledby = htmlQuery($html, "//section[@data-section='{$section}']/@aria-labelledby")->item(0)?->nodeValue;

        expect($labelledby)->not->toBeNull()
            ->and(htmlQuery($html, "//*[@id='{$labelledby}']")->length)->toBe(1);
    }
});

test('the two argument sections never call themselves a problem or a solution', function () {
    popularStories(45);

    $text = strtolower(strip_tags($this->get(route('landing'))->getContent()));

    expect($text)->not->toContain('the problem')->not->toContain('the solution');
});

test('the two argument sections are an editorial list and a walkthrough, not rows of icon cards', function () {
    popularStories(45);

    $html = $this->get(route('landing'))->getContent();

    // No icons in either, and the walkthrough is four statements beside a pinned preview, not a grid of cards.
    expect(htmlQuery($html, '//section[@data-section="scattered" or @data-section="together"]//svg')->length)->toBe(0)
        ->and(htmlQuery($html, '//section[@data-section="together"]//article')->length)->toBe(4);
});

test('the top 10 lists the ten most popular stories with a cover, numbered 1 to 10 in popularity order, twice for the loop', function () {
    popularStories(14);
    Media::factory()->create(['title' => 'Hit Without Cover', 'cover_url' => null, 'popularity_score' => 5]);

    $html = $this->get(route('landing'))->assertOk()->getContent();

    $expected = array_map(fn (int $n): string => "Number {$n}: Popular Tale {$n}", range(1, 10));

    expect(landingTexts($html, '//section[@data-section="top-ten"]//ol[@aria-label]//li/span[@class="sr-only"]'))->toBe($expected)
        ->and(landingTexts($html, '//section[@data-section="top-ten"]//ol[@aria-label]//span[contains(@class,"landing-top__rank")]'))->toBe(array_map('strval', range(1, 10)))
        ->and(htmlQuery($html, '//section[@data-section="top-ten"]//ol')->length)->toBe(2)
        ->and(landingTexts($html, '//section[@data-section="top-ten"]//ol[@aria-hidden="true"]//span[contains(@class,"landing-top__rank")]'))->toBe(array_map('strval', range(1, 10)))
        ->and($html)->not->toContain('Hit Without Cover');
});

test('the top 10 copy is hidden from assistive tech and offers nothing to tab to; the posters link nowhere', function () {
    popularStories(12);

    $html = $this->get(route('landing'))->getContent();

    expect(htmlQuery($html, '//section[@data-section="top-ten"]//ol[@aria-hidden="true"]//a | //section[@data-section="top-ten"]//ol[@aria-hidden="true"]//button')->length)->toBe(0)
        ->and(htmlQuery($html, '//section[@data-section="top-ten"]//ol//a')->length)->toBe(0)
        ->and(htmlQuery($html, '//section[@data-section="top-ten"]//button[@aria-pressed]')->length)->toBe(1);
});

test('the hero shows seven books picked at random from the forty most popular, the front one as the scene book', function () {
    popularStories(60);
    $top = collect(range(1, 40))->map(fn (int $n): string => "Popular Tale {$n}");

    $html = $this->get(route('landing'))->getContent();

    $books = landingTexts($html, '//section[@data-section="hero"]//*[contains(@class,"landing-book__float")]//*[@data-book-fallback]');
    $slots = collect(iterator_to_array(htmlQuery($html, '//section[@data-section="hero"]//*[@data-slot]')))->map(fn (DOMElement $book): string => $book->getAttribute('data-slot'))->all();

    expect($books)->toHaveCount(7)->and(collect($books)->unique())->toHaveCount(7)
        ->and(collect($books)->diff($top))->toBeEmpty()
        ->and($slots)->toBe(['0', '1', '2', '3', '4', '5', '6'])
        ->and(htmlQuery($html, '//section[@data-section="hero"]//*[@data-scene-cover]')->length)->toBe(1)
        ->and(htmlQuery($html, '//section[@data-section="hero"]//*[@data-scene-cover]/ancestor::*[contains(@class,"landing-book")]')->item(0)?->getAttribute('data-slot'))->toBe('0')
        ->and(htmlQuery($html, '//section[@data-section="hero"]//*[@data-scene-cover]')->item(0)?->getAttribute('data-scene-cover'))->toStartWith('/covers/');
});

test('the hero has no links or buttons of its own, and its copy is centred', function () {
    popularStories(45);

    $html = $this->get(route('landing'))->getContent();

    expect(htmlQuery($html, '//section[@data-section="hero"]//a | //section[@data-section="hero"]//button | //section[@data-section="hero"]//*[@href or @tabindex]')->length)->toBe(0)
        ->and(landingTexts($html, '//section[@data-section="hero"]//h1'))->toBe(['Your Library. Every Story.'])
        ->and(htmlQuery($html, '//section[@data-section="hero"]//*[contains(@class,"landing-hero__copy")][contains(@class,"text-center")]//h1')->length)->toBe(1)
        ->and(htmlQuery($html, '//section[@data-section="hero"]//*[contains(@class,"landing-hero__copy")]/p')->length)->toBe(2)
        ->and(landingTexts($html, '//section[@data-section="hero"]//*[contains(@class,"landing-hero__copy")]/p')[1])->toStartWith('Bookflix keeps every comic, manga, novel and webcomic');
});

test('the hero names three of its books in a caption under the stage, and no title in it is long', function () {
    popularStories(45);

    $caption = landingTexts($this->get(route('landing'))->getContent(), '//section[@data-section="hero"]//p[contains(@class,"landing-hero__caption")]');

    expect($caption)->toHaveCount(1)
        ->and($caption[0])->toStartWith('Popular right now: Popular Tale ')
        ->and(substr_count($caption[0], 'Popular Tale '))->toBe(3);
});

test('the hero picks other books on another visit', function () {
    popularStories(60);

    $picks = collect(range(1, 6))->map(fn () => implode('|', landingTexts($this->get(route('landing'))->getContent(), '//section[@data-section="hero"]//*[@data-book-fallback]')))->unique();

    expect($picks->count())->toBeGreaterThan(1);
});

test('a small catalog fills the hero by repeating covers, and an empty one leaves a text-only hero and no top 10', function () {
    popularStories(3);

    expect(landingTexts($this->get(route('landing'))->getContent(), '//section[@data-section="hero"]//*[@data-book-fallback]'))->toHaveCount(7);

    Cache::flush();
    Media::query()->forceDelete();

    $html = $this->get(route('landing'))->assertOk()->getContent();

    expect(landingTexts($html, '//section[@data-section="hero"]//h1'))->toBe(['Your Library. Every Story.'])
        ->and(htmlQuery($html, '//*[@data-book-fallback]')->length)->toBe(0)
        ->and(htmlQuery($html, '//section[@data-section="top-ten"]')->length)->toBe(0)
        ->and(htmlQuery($html, '//section[@data-section="faq"]')->length)->toBe(1);
});

test('the FAQ is exactly five questions, each a button over a region it names', function () {
    popularStories(12);

    $html = $this->get(route('landing'))->getContent();

    expect(htmlQuery($html, '//section[@data-section="faq"]//*[@data-faq-item]')->length)->toBe(5)
        ->and(htmlQuery($html, '//section[@data-section="faq"]//h3/button[@data-faq-button]')->length)->toBe(5)
        ->and(landingTexts($html, '//section[@data-section="faq"]//h2'))->toBe(['Frequently Asked Questions']);

    foreach (htmlQuery($html, '//section[@data-section="faq"]//button[@data-faq-button]') as $button) {
        $answer = $button->getAttribute('aria-controls');

        expect($button->getAttribute('aria-expanded'))->toBe('false')
            ->and(htmlQuery($html, "//*[@id='{$answer}'][@role='region'][@aria-labelledby='{$button->getAttribute('id')}']")->length)->toBe(1);
    }
});

test('the call to action buttons go to register and login: the header, the closing section and the footer, never the hero', function () {
    popularStories(12);

    $html = $this->get(route('landing'))->getContent();

    expect(htmlQuery($html, "//section[@data-section='hero']//a[@href='".route('register')."' or @href='".route('login')."']")->length)->toBe(0)
        ->and(htmlQuery($html, "//section[@data-section='closing']//a[@href='".route('register')."']")->length)->toBe(1)
        ->and(htmlQuery($html, "//section[@data-section='closing']//a[@href='".route('login')."']")->length)->toBe(1)
        ->and(htmlQuery($html, '//header//a[@href="'.route('login').'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//header//a[@href="'.route('register').'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//footer//a[@href="'.route('register').'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//footer//a[@href="'.route('login').'"]')->length)->toBe(1);
});

test('the sign-up box under the FAQ posts a labelled email to the start route', function () {
    popularStories(12);

    $html = $this->get(route('landing'))->getContent();

    expect(htmlQuery($html, '//form[@id="start"][@method="POST"][@action="'.route('landing.start').'"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//form[@id="start"]//input[@name="_token"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//form[@id="start"]//input[@type="email"][@name="email"][@required][@id="start-email"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//form[@id="start"]//label[@for="start-email"]')->length)->toBe(1);
});

test('a bad email shows its message in the sign-up box, tied to the input', function () {
    popularStories(12);

    $this->from(route('landing'))->post(route('landing.start'), ['email' => 'nope']);

    $html = $this->get(route('landing'))->getContent();

    expect(htmlQuery($html, '//input[@id="start-email"][@aria-invalid="true"][@aria-describedby="start-error"]')->length)->toBe(1)
        ->and(htmlQuery($html, '//*[@id="start-error"][@role="alert"]')->length)->toBe(1)
        ->and($html)->toContain('value="nope"');
});

test('the footer credits the data sources and has a legal line', function () {
    popularStories(12);

    $text = landingTexts($this->get(route('landing'))->getContent(), '//footer')[0];

    expect($text)->toContain('Data from AniList, MangaDex, MangaUpdates, Open Library')
        ->and($text)->toContain('© '.now()->year.' Bookflix. All rights reserved.');
});

test('the page costs one query when cold and none when warm', function () {
    popularStories(45);

    DB::enableQueryLog();
    $this->get(route('landing'))->assertOk();
    $cold = DB::getQueryLog();

    DB::flushQueryLog();
    $this->get(route('landing'))->assertOk();
    $warm = DB::getQueryLog();

    expect($cold)->toHaveCount(1)->and($cold[0]['query'])->toContain('popularity_score')
        ->and($warm)->toBeEmpty();
});

test('the page loads no script from another origin, and nothing three.js on first paint', function () {
    popularStories(12);

    $html = $this->get(route('landing'))->getContent();

    foreach (htmlQuery($html, '//script[@src]') as $script) {
        expect($script->getAttribute('src'))->toStartWith(url('/'));
    }

    expect($html)->not->toContain('three.module');
});
