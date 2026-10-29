<?php

use App\Catalog\Classification\ClassificationSignals;
use App\Catalog\Classification\MediaTypeClassifier;
use App\Catalog\Classification\MediaTypeVerdict;
use App\Catalog\Classification\PublisherCatalog;

/**
 * @param  array<string, mixed>  $signals
 */
function classified_as(array $signals): string
{
    return (new MediaTypeClassifier)->classify(new ClassificationSignals(...$signals))->slug;
}

/**
 * @param  array<string, mixed>  $signals
 */
function verdict_of(array $signals): MediaTypeVerdict
{
    return (new MediaTypeClassifier)->classify(new ClassificationSignals(...$signals));
}

test('the provider naming the format settles it', function (array $signals, string $slug) {
    expect(classified_as($signals))->toBe($slug)->and(verdict_of($signals)->confidence)->toBeGreaterThan(0.9);
})->with([
    'AniList novel' => [['provider' => 'anilist', 'format' => 'NOVEL', 'country' => 'JP'], 'light-novel'],
    'AniList Korean web novel' => [['provider' => 'anilist', 'format' => 'NOVEL', 'country' => 'KR'], 'light-novel'],
    'AniList manga' => [['provider' => 'anilist', 'format' => 'MANGA', 'country' => 'JP'], 'manga'],
    'AniList one shot' => [['provider' => 'anilist', 'format' => 'ONE_SHOT', 'country' => 'JP'], 'manga'],
    'AniList manhwa' => [['provider' => 'anilist', 'format' => 'MANGA', 'country' => 'KR'], 'manhwa'],
    'AniList manhua' => [['provider' => 'anilist', 'format' => 'MANGA', 'country' => 'CN'], 'manhua'],
    'AniList Taiwanese manhua' => [['provider' => 'anilist', 'format' => 'MANGA', 'country' => 'TW'], 'manhua'],
    'MangaUpdates manga' => [['provider' => 'mangaupdates', 'format' => 'Manga', 'country' => 'JP'], 'manga'],
    'MangaUpdates manhwa' => [['provider' => 'mangaupdates', 'format' => 'Manhwa', 'country' => 'KR'], 'manhwa'],
    'MangaUpdates manhua' => [['provider' => 'mangaupdates', 'format' => 'Manhua', 'country' => 'CN'], 'manhua'],
    'MangaUpdates novel' => [['provider' => 'mangaupdates', 'format' => 'Novel'], 'light-novel'],
]);

test('formats that are not books, and comics from countries the catalogue does not take, are not classified', function (array $signals) {
    expect(classified_as($signals))->toBe('')->and(verdict_of($signals)->isSupported())->toBeFalse();
})->with([
    'AniList music' => [['provider' => 'anilist', 'format' => 'MUSIC', 'country' => 'JP']],
    'AniList no format' => [['provider' => 'anilist', 'country' => 'JP']],
    'AniList manga from the US' => [['provider' => 'anilist', 'format' => 'MANGA', 'country' => 'US']],
    'MangaUpdates doujinshi' => [['provider' => 'mangaupdates', 'format' => 'Doujinshi']],
    'MangaUpdates artbook' => [['provider' => 'mangaupdates', 'format' => 'Artbook']],
    'MangaDex French original' => [['provider' => 'mangadex', 'language' => 'fr']],
    'MangaDex no language' => [['provider' => 'mangadex']],
]);

test('MangaDex follows the original language', function (string $language, string $slug) {
    expect(classified_as(['provider' => 'mangadex', 'language' => $language]))->toBe($slug);
})->with([['ja', 'manga'], ['ko', 'manhwa'], ['zh', 'manhua'], ['zh-hk', 'manhua']]);

test('a MangaDex long strip is decided by country: Korean manhwa, Chinese manhua, Japanese and other work a webcomic', function (string $language, string $slug) {
    expect(classified_as(['provider' => 'mangadex', 'language' => $language, 'subjects' => ['Long Strip', 'Comedy']]))->toBe($slug);
})->with([['ko', 'manhwa'], ['zh', 'manhua'], ['zh-hk', 'manhua'], ['ja', 'webcomic'], ['en', 'webcomic'], ['fr', 'webcomic'], ['es', 'webcomic']]);

test('a web comic tag alone changes work from elsewhere but leaves Japanese work manga', function (string $language, string $slug) {
    expect(classified_as(['provider' => 'mangadex', 'language' => $language, 'subjects' => ['Web Comic']]))->toBe($slug);
})->with([['ja', 'manga'], ['ko', 'manhwa'], ['en', 'webcomic']]);

test('webtoons from the book catalogues follow the country too', function (array $signals, string $slug) {
    expect(classified_as(['provider' => 'hardcover', ...$signals]))->toBe($slug);
})->with([
    'a Korean webtoon' => [['subjects' => ['Webtoon', 'Fantasy'], 'language' => 'ko'], 'manhwa'],
    'an English webcomic' => [['subjects' => ['Webcomics'], 'pageCount' => 220], 'webcomic'],
]);

test('MangaDex light novel evidence outvotes the language, an alternative title alone does not', function () {
    expect(classified_as(['provider' => 'mangadex', 'language' => 'ja', 'subjects' => ['Light Novel']]))->toBe('light-novel')
        ->and(classified_as(['provider' => 'mangadex', 'language' => 'ja', 'alternativeTitles' => ['Tensei Shitara (Light Novel)']]))->toBe('manga')
        ->and(classified_as(['provider' => 'mangadex', 'language' => 'ko', 'subjects' => ['Web Novel']]))->toBe('light-novel')
        ->and(classified_as([
            'provider' => 'mangadex', 'language' => 'ja', 'alternativeTitles' => ['Overlord (Light Novel)'],
            'description' => 'Overlord is a light novel series about a player trapped in a game.',
        ]))->toBe('light-novel');
});

test('a manga adapted from a light novel or a visual novel stays a manga', function (array $signals) {
    expect(classified_as($signals))->toBe('manga');
})->with([
    'AniList says manga' => [['provider' => 'anilist', 'format' => 'MANGA', 'country' => 'JP', 'description' => 'This is the manga adaptation of the light novel by Reki Kawahara. It is a visual novel at heart.']],
    'MangaDex adaptation blurb' => [['provider' => 'mangadex', 'language' => 'ja', 'description' => 'Based on the light novel of the same name, this is a visual novel adaptation.']],
    'Open Library manga of a visual novel' => [['provider' => 'openlibrary', 'subjects' => ['Manga', 'Visual novels', 'Comic books, strips, etc.'], 'publishers' => ['Yen Press'], 'pageCount' => 200]],
]);

test('a Comic Vine volume named as a novel is a novel, whoever the publisher is', function (array $signals) {
    expect(classified_as(['provider' => 'comicvine', ...$signals]))->toBe('novel');
})->with([
    'Batman: The Novel' => [['title' => 'Batman: The Novel', 'publishers' => ['DC Comics']]],
    'a parenthesised novel' => [['title' => 'Spider-Man: Venom Factor (Novel)', 'publishers' => ['Marvel']]],
    'a novelization' => [['title' => 'The Dark Knight Rises: The Novelization', 'publishers' => ['Titan Books']]],
    'a prose edition' => [['title' => 'Star Wars: Thrawn (Prose)', 'publishers' => ['Del Rey']]],
    'A Novel' => [['title' => 'Wonder Woman: Warbringer, A Novel', 'publishers' => ['DC Comics']]],
]);

test('a Comic Vine volume is a comic book by default and a graphic novel when it is a collected edition', function (array $signals, string $slug) {
    expect(classified_as(['provider' => 'comicvine', ...$signals]))->toBe($slug);
})->with([
    'an issue based series' => [['title' => 'Batman', 'publishers' => ['DC Comics'], 'chapterCount' => 713], 'comic-book'],
    'no publisher' => [['title' => 'The Amazing Spider-Man'], 'comic-book'],
    'a deluxe edition' => [['title' => 'Batman: The Long Halloween Deluxe Edition', 'publishers' => ['DC Comics']], 'graphic-novel'],
    'an omnibus' => [['title' => 'X-Men Omnibus', 'publishers' => ['Marvel Comics']], 'graphic-novel'],
    'a graphic novel' => [['title' => 'Batman: Hush (Graphic Novel)', 'publishers' => ['DC Comics']], 'graphic-novel'],
]);

test('Comic Vine light novels are light novels', function () {
    expect(classified_as(['provider' => 'comicvine', 'title' => 'Sword Art Online (Light Novel)', 'publishers' => ['Yen On']]))->toBe('light-novel')
        ->and(classified_as(['provider' => 'comicvine', 'title' => 'Re:Zero', 'publishers' => ['Yen On']]))->toBe('light-novel');
});

test('Metron collected editions are graphic novels, series are comic books, and a hardcover novel is a novel', function (array $signals, string $slug) {
    expect(classified_as(['provider' => 'metron', ...$signals]))->toBe($slug);
})->with([
    'ongoing series' => [['format' => 'Ongoing Series', 'title' => 'Batman', 'publishers' => ['DC Comics'], 'chapterCount' => 713], 'comic-book'],
    'limited series' => [['format' => 'Limited Series', 'title' => 'Watchmen', 'publishers' => ['DC Comics']], 'comic-book'],
    'single issue' => [['format' => 'Single Issue', 'title' => 'Saga #1', 'publishers' => ['Image Comics']], 'comic-book'],
    'one shot' => [['format' => 'One-Shot', 'title' => 'Batman: Black and White', 'publishers' => ['DC Comics']], 'comic-book'],
    'trade paperback' => [['format' => 'Trade Paperback', 'title' => 'Saga Vol. 1', 'publishers' => ['Image Comics']], 'graphic-novel'],
    'hardcover' => [['format' => 'Hardcover', 'title' => 'Absolute Batman', 'publishers' => ['DC Comics']], 'graphic-novel'],
    'omnibus' => [['format' => 'Omnibus', 'title' => 'Fantastic Four Omnibus', 'publishers' => ['Marvel']], 'graphic-novel'],
    'nothing but a series type' => [['format' => 'Hardcover', 'title' => 'Untitled'], 'graphic-novel'],
    'a hardcover that is a novel' => [['format' => 'Hardcover', 'title' => 'Superman: Last Son of Krypton, The Novel', 'publishers' => ['DC Comics']], 'novel'],
    'a trade paperback novelization' => [['format' => 'Trade Paperback', 'title' => 'Batman Begins: The Novelization', 'publishers' => ['Pocket Books']], 'novel'],
]);

test('a novel tagged Graphic Novels on Hardcover is a novel, a real graphic novel is not', function (array $signals, string $slug) {
    expect(classified_as(['provider' => 'hardcover', ...$signals]))->toBe($slug);
})->with([
    'a tagged tie-in novel' => [['title' => 'Batman: Fear Itself', 'subjects' => ['Graphic Novels', 'Fantasy', 'Science Fiction'], 'creatorRoles' => ['Author'], 'pageCount' => 380], 'novel'],
    'a long prose adaptation' => [['title' => 'Spider-Man: Forever Young', 'subjects' => ['Graphic Novels', 'Fiction'], 'creatorRoles' => ['Author'], 'pageCount' => 512], 'novel'],
    'a novelization blurb' => [['title' => 'Star Trek', 'subjects' => ['Graphic Novels'], 'creatorRoles' => ['Author'], 'description' => 'The novelization of the hit movie.'], 'novel'],
    'only the tag and a book length' => [['title' => 'Monstress, Vol. 1', 'subjects' => ['Graphic Novels'], 'creatorRoles' => ['Author'], 'pageCount' => 192], 'graphic-novel'],
    'tag and comics credits' => [['title' => 'Saga', 'subjects' => ['Graphic Novels', 'Fantasy'], 'creatorRoles' => ['Author', 'Illustrator', 'Colorist']], 'graphic-novel'],
    'tag and comics genre' => [['title' => 'Maus', 'subjects' => ['Graphic Novels', 'Comics', 'History']], 'graphic-novel'],
    'a manga tag' => [['title' => 'Berserk, Vol. 1', 'subjects' => ['Manga', 'Fantasy'], 'pageCount' => 224], 'manga'],
    'a single issue' => [['title' => 'Saga #1', 'subjects' => ['Comics'], 'pageCount' => 32], 'comic-book'],
    'plain comics' => [['title' => 'Saga', 'subjects' => ['Comics']], 'graphic-novel'],
    'a plain novel' => [['title' => 'Dune', 'subjects' => ['Fantasy', 'Science Fiction'], 'creatorRoles' => ['Author'], 'pageCount' => 412], 'novel'],
    'nothing at all' => [['title' => 'Untitled'], 'novel'],
]);

test('an Open Library novel adapted from a comic is a novel when the rest of the evidence says prose', function (array $signals) {
    expect(classified_as(['provider' => 'openlibrary', ...$signals]))->toBe('novel');
})->with([
    'a prose publisher and a long book' => [['title' => "Batman: No Man's Land", 'subjects' => ['Comic books, strips, etc.', 'Graphic novels', 'Fiction', 'Superheroes'], 'publishers' => ['Pocket Books'], 'pageCount' => 420]],
    'a novelization blurb' => [['title' => 'Daredevil', 'subjects' => ['Comic books, strips, etc.', 'Graphic novels', 'Fiction'], 'description' => 'A novelization of the comic book storyline.', 'pageCount' => 300]],
    'novel in the subjects' => [['title' => 'Iron Man: Extremis', 'subjects' => ['Graphic novels', 'Novels', 'Fiction'], 'pageCount' => 340]],
]);

test('Open Library comics are graphic novels unless the evidence says a single issue or manga', function (array $signals, string $slug) {
    expect(classified_as(['provider' => 'openlibrary', ...$signals]))->toBe($slug);
})->with([
    'a graphic novel' => [['title' => 'Watchmen', 'subjects' => ['Comic books, strips, etc.', 'Graphic novels', 'Superheroes'], 'publishers' => ['DC Comics'], 'pageCount' => 416], 'graphic-novel'],
    'a manga volume' => [['title' => 'One Piece, Vol. 1', 'subjects' => ['Manga', 'Comic books, strips, etc.'], 'publishers' => ['Viz Media'], 'pageCount' => 208, 'language' => 'en'], 'manga'],
    'a manhwa volume' => [['title' => 'Solo Leveling, Vol. 1', 'subjects' => ['Manhwa', 'Comics & graphic novels'], 'pageCount' => 300], 'manhwa'],
    'a floppy' => [['title' => 'Amazing Spider-Man #1', 'subjects' => ['Comic books, strips, etc.'], 'publishers' => ['Marvel Comics'], 'pageCount' => 32], 'comic-book'],
    'a plain novel' => [['title' => 'Dune', 'subjects' => ['Fiction', 'Science fiction', 'Ecology'], 'publishers' => ['Ace Books'], 'pageCount' => 412], 'novel'],
    'a picture book' => [['title' => 'Where the Wild Things Are', 'subjects' => ['Picture books', 'Juvenile fiction'], 'pageCount' => 40], 'picture-book'],
    'a novel with picture books in the subjects but a long page count' => [['title' => 'The Princess Bride', 'subjects' => ['Fiction', 'Fantasy fiction'], 'pageCount' => 480], 'novel'],
]);

test('light novels are told by imprint, subject or description', function (array $signals) {
    expect(classified_as($signals))->toBe('light-novel');
})->with([
    'Yen On' => [['provider' => 'openlibrary', 'title' => 'Sword Art Online 1', 'publishers' => ['Yen On'], 'subjects' => ['Fiction', 'Fantasy fiction'], 'pageCount' => 240]],
    'J-Novel Club' => [['provider' => 'openlibrary', 'title' => 'Mushoku Tensei', 'publishers' => ['J-Novel Club']]],
    'Seven Seas Airship' => [['provider' => 'openlibrary', 'title' => 'Skeleton Knight', 'publishers' => ['Seven Seas Airship']]],
    'Kodansha Ranobe Bunko' => [['provider' => 'openlibrary', 'title' => 'Vinland', 'publishers' => ['Kodansha Ranobe Bunko']]],
    'Dengeki Bunko' => [['provider' => 'hardcover', 'title' => 'Toaru Majutsu no Index', 'publishers' => ['Dengeki Bunko']]],
    'a light novel subject' => [['provider' => 'openlibrary', 'title' => 'Spice and Wolf', 'subjects' => ['Light novels', 'Fantasy']]],
    'a Hardcover tag' => [['provider' => 'hardcover', 'title' => 'Overlord', 'subjects' => ['Light Novels']]],
    'a title marker' => [['provider' => 'comicvine', 'title' => 'Konosuba (Light Novel)']],
    'a web novel from Korea' => [['provider' => 'hardcover', 'title' => 'Omniscient Reader', 'subjects' => ['Web Novel', 'Fantasy'], 'language' => 'ko']],
]);

test('a web novel from outside Asia is a plain novel', function () {
    expect(classified_as(['provider' => 'hardcover', 'title' => 'Worm', 'subjects' => ['Web Novel', 'Superheroes'], 'language' => 'en']))->toBe('novel');
});

test('manga and comic publishers are evidence for comics', function (string $publisher, string $slug) {
    expect(classified_as(['provider' => 'openlibrary', 'title' => 'Something', 'subjects' => ['Comic books, strips, etc.'], 'publishers' => [$publisher], 'pageCount' => 180]))->toBe($slug);
})->with([
    ['Viz Media', 'manga'],
    ['Shueisha', 'manga'],
    ['Kodansha Comics', 'manga'],
    ['Dark Horse Comics', 'graphic-novel'],
    ['Image Comics', 'graphic-novel'],
    ['Boom! Studios', 'graphic-novel'],
    ['IDW Publishing', 'graphic-novel'],
]);

test('visual novels are told from the subjects, but a comic adapting one is not', function () {
    expect(classified_as(['provider' => 'hardcover', 'title' => 'Steins;Gate', 'subjects' => ['Visual Novel', 'Science Fiction']]))->toBe('visual-novel')
        ->and(classified_as(['provider' => 'openlibrary', 'title' => 'Clannad', 'subjects' => ['Visual novels', 'Video games']]))->toBe('visual-novel')
        ->and(classified_as(['provider' => 'openlibrary', 'title' => 'Ace Attorney Visual Novel', 'description' => 'Phoenix Wright is a visual novel adventure game.']))->toBe('visual-novel');
});

test('picture books need the subject, not just a short page count', function () {
    expect(classified_as(['provider' => 'openlibrary', 'title' => 'Goodnight Moon', 'subjects' => ['Picture books']]))->toBe('picture-book')
        ->and(classified_as(['provider' => 'openlibrary', 'title' => 'A short story', 'subjects' => ['Fiction'], 'pageCount' => 40]))->not->toBe('picture-book');
});

test('prose wins a tie and an unknown record is a novel; only Comic Vine and Metron default to a comic', function () {
    expect(classified_as([]))->toBe('novel')
        ->and(classified_as(['provider' => 'openlibrary']))->toBe('novel')
        ->and(classified_as(['provider' => 'hardcover', 'subjects' => ['Graphic Novels'], 'pageCount' => 320, 'creatorRoles' => ['Author']]))->toBe('novel')
        ->and(classified_as(['provider' => 'comicvine']))->toBe('comic-book')
        ->and(classified_as(['provider' => 'metron']))->toBe('comic-book');
});

test('the verdict says why and how sure it is', function () {
    $sure = verdict_of(['provider' => 'anilist', 'format' => 'NOVEL', 'country' => 'JP']);
    $unsure = verdict_of(['provider' => 'openlibrary', 'title' => 'Maybe', 'subjects' => ['Graphic novels'], 'pageCount' => 300]);
    $titled = verdict_of(['provider' => 'comicvine', 'title' => 'Batman: The Novel', 'publishers' => ['DC Comics']]);

    expect($sure->confidence)->toBeGreaterThanOrEqual(0.95)->and($sure->reasons)->toContain('AniList format NOVEL')
        ->and($unsure->confidence)->toBeLessThan(0.65)
        ->and($titled->slug)->toBe('novel')->and($titled->confidence)->toBeGreaterThan(0.6)->and($titled->reasons[0])->toBe('title says novel');
});

test('accents and case do not matter', function () {
    expect(classified_as(['provider' => 'comicvine', 'title' => 'BATMAN: THE NOVEL']))->toBe('novel')
        ->and(classified_as(['provider' => 'openlibrary', 'subjects' => ['COMIC BOOKS, STRIPS, ETC.', 'Mangá'], 'pageCount' => 180]))->not->toBe('novel');
});

test('publisher names are normalized and house names that publish both are left out', function () {
    expect(PublisherCatalog::kinds('Boom! Studios'))->toBe(['comic'])
        ->and(PublisherCatalog::kinds('Marvel Comics Group'))->toBe(['comic'])
        ->and(PublisherCatalog::kinds('J-Novel Club'))->toBe(['light-novel'])
        ->and(PublisherCatalog::kinds('Yen On'))->toBe(['light-novel'])
        ->and(PublisherCatalog::kinds('Yen Press'))->toBe(['manga'])
        ->and(PublisherCatalog::kinds('Seven Seas Airship'))->toBe(['light-novel'])
        ->and(PublisherCatalog::kinds('Seven Seas Entertainment'))->toBe(['manga'])
        ->and(PublisherCatalog::kinds('Kodansha'))->toBe([])
        ->and(PublisherCatalog::kinds('Del Rey'))->toBe([])
        ->and(PublisherCatalog::kinds('Penguin Random House LLC'))->toBe(['prose'])
        ->and(PublisherCatalog::kinds('Image'))->toBe(['comic'])
        ->and(PublisherCatalog::kinds('Imagine That'))->toBe([])
        ->and(PublisherCatalog::kinds('DC'))->toBe(['comic'])
        ->and(PublisherCatalog::kinds(''))->toBe([]);
});
