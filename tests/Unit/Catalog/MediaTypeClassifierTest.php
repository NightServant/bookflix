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

test('Open Library subject strings in BISAC and LCSH shapes are read, not only exact phrases', function (array $signals, string $slug) {
    expect(classified_as(['provider' => 'openlibrary', ...$signals]))->toBe($slug);
})->with([
    'Attack on Titan, as Open Library has it' => [[
        'title' => 'Attack on Titan', 'pageCount' => 193, 'publishers' => ['Kodansha Comics', 'Turtleback Books', 'Carlsen Verlag GmbH'],
        'subjects' => ['Giants', 'Comic books, strips', 'Comics & graphic novels, manga, science fiction', 'Graphic novels', 'Comic books, strips, etc--japan', 'Comics & graphic novels, east asian style, manga, science fiction'],
    ], 'manga'],
    'a BISAC manga heading alone' => [['subjects' => ['COMICS & GRAPHIC NOVELS / Manga / Action'], 'pageCount' => 192], 'manga'],
    'an ampersand comics heading' => [['subjects' => ['Comics & graphic novels'], 'pageCount' => 180], 'graphic-novel'],
    'a BISAC juvenile graphic novel' => [['subjects' => ['JUVENILE FICTION / Comics & Graphic Novels / General'], 'pageCount' => 224], 'graphic-novel'],
    'Japanese comics by LCSH place' => [['subjects' => ['Comic books, strips, etc. -- Japan']], 'manga'],
    'Korean comics by LCSH place' => [['subjects' => ['Comic books, strips, etc. -- Korea (South)']], 'manhwa'],
    'Chinese comics by LCSH place' => [['subjects' => ['Comic books, strips, etc. -- China']], 'manhua'],
]);

test('a book about comics is not a comic, and a novel about cartoonists is a novel', function (array $signals, string $slug) {
    expect(classified_as(['provider' => 'openlibrary', ...$signals]))->toBe($slug);
})->with([
    'The Amazing Adventures of Kavalier & Clay' => [[
        'title' => 'The Amazing Adventures of Kavalier & Clay', 'deweyCode' => '813.54', 'publishers' => ['Random House', 'Picador'],
        'subjects' => ['Comic books, strips', 'Comic books, strips, etc. in fiction', 'Comic books, strips, etc. -- Fiction', 'Cartoonists -- Fiction', 'Fiction', 'Humorous fiction', 'Technique', 'Authorship'],
    ], 'novel'],
    'How to Draw Manga' => [['title' => 'How to Draw Manga', 'subjects' => ['Art', 'Manga', 'Technique', 'Drawing', 'Comic books, strips'], 'publishers' => ['Graphic-Sha'], 'pageCount' => 132], 'nonfiction'],
    'a manga technique heading' => [['title' => 'Manga Mania', 'subjects' => ['Manga -- Technique', 'Cartooning -- Technique']], 'nonfiction'],
    'a history of comics by Dewey' => [['title' => 'The Ten-Cent Plague', 'deweyCode' => '741.509'], 'nonfiction'],
    'Diary of a Wimpy Kid, a novel with one comics heading' => [[
        'title' => 'Diary of a Wimpy Kid', 'deweyCode' => '813.6', 'publishers' => ['Amulet Books'],
        'subjects' => ['School stories', 'Diaries', 'Fiction', "Children's stories", 'Juvenile fiction', 'Comics & Graphic Novels', 'Humorous fiction', 'JUVENILE FICTION -- Humorous Stories'],
    ], 'novel'],
    'Understanding Comics, a comic about comics' => [[
        'title' => 'Understanding Comics', 'deweyCode' => '741.5', 'pageCount' => 215, 'publishers' => ['Kitchen Sink Press', 'DC Comics'],
        'subjects' => ['Comics', 'Manga', 'Technique', 'History and criticism', 'Cartooning', 'Comic books, strips, etc., history and criticism'],
    ], 'graphic-novel'],
]);

test('the Dewey class an edition carries is evidence for the family and the comic form', function (string $dewey, string $slug) {
    expect(classified_as(['provider' => 'openlibrary', 'title' => 'Something', 'deweyCode' => $dewey]))->toBe($slug);
})->with([
    'Japanese comics' => ['741.5952', 'manga'],
    'Korean comics' => ['741.595195', 'manhwa'],
    'Chinese comics' => ['741.5951', 'manhua'],
    'Taiwanese comics' => ['741.5951249', 'manhua'],
    'American comics' => ['741.5973', 'graphic-novel'],
    'comics, no place' => ['741.5', 'graphic-novel'],
    'American fiction' => ['813.54', 'novel'],
    'Japanese fiction' => ['895.636', 'novel'],
    'psychology' => ['155.24', 'nonfiction'],
    'history' => ['909', 'nonfiction'],
    'garbage' => ['FIC', 'novel'],
]);

test('nonfiction is its own type, told by Dewey and subjects, and fiction outvotes a stray nonfiction tag', function (array $signals, string $slug) {
    expect(classified_as($signals))->toBe($slug);
})->with([
    'Atomic Habits on Open Library' => [[
        'provider' => 'openlibrary', 'title' => 'Atomic Habits', 'deweyCode' => '155.24',
        'subjects' => ['Habit', 'Behavior modification', 'Self-actualization (psychology)', 'Business', 'psychology', 'Personal Growth', 'BUSINESS & ECONOMICS / Organizational Behavior', 'SELF-HELP / Personal Growth / General.'],
    ], 'nonfiction'],
    'Atomic Habits on Hardcover' => [['provider' => 'hardcover', 'title' => 'Atomic Habits', 'subjects' => ['Nonfiction', 'Self Help', 'Personal Development', 'Business', 'Psychology'], 'pageCount' => 320], 'nonfiction'],
    'Sapiens' => [['provider' => 'openlibrary', 'title' => 'Sapiens', 'deweyCode' => '909', 'subjects' => ['History', 'Non-Fiction', 'Science', 'World history']], 'nonfiction'],
    'Educated' => [['provider' => 'openlibrary', 'title' => 'Educated', 'deweyCode' => '270.092', 'subjects' => ['Biography', 'BIOGRAPHY & AUTOBIOGRAPHY / Personal Memoirs', 'Home schooling'], 'pageCount' => 376], 'nonfiction'],
    'true crime on Hardcover' => [['provider' => 'hardcover', 'title' => 'In Cold Blood', 'subjects' => ['True Crime', 'Nonfiction', 'Mystery'], 'pageCount' => 343], 'nonfiction'],
    'Hugo Cabret, a novel with a History subject' => [['provider' => 'openlibrary', 'title' => 'The Invention of Hugo Cabret', 'deweyCode' => '813.54', 'subjects' => ['historical fiction', 'Juvenile fiction', 'Fiction', 'History', 'Orphans']], 'novel'],
    'The Great Gatsby with a stray nonfiction genre' => [['provider' => 'hardcover', 'title' => 'The Great Gatsby', 'subjects' => ['Fiction', 'Literature Fiction', 'Romance', 'Classics', 'Nonfiction and Narrative Nonfiction'], 'creatorRoles' => ['author'], 'pageCount' => 180], 'novel'],
    'Caraval with a stray nonfiction genre' => [['provider' => 'hardcover', 'title' => 'Caraval', 'subjects' => ['Fantasy', 'Romance', 'Mystery, Crime and Thriller', 'Romantasy', 'Nonfiction and Narrative Nonfiction'], 'pageCount' => 416], 'novel'],
    'The Kite Runner as stored, a polluted nonfiction genre against reader fiction tags' => [['provider' => 'hardcover', 'title' => 'The Kite Runner', 'pageCount' => 401, 'subjects' => [
        'Reflective', 'Sad', 'Not Diverse Characters', 'Unloveable Characters', 'Strong Character Development', 'A Mix Driven', 'Character Driven', 'Loveable Characters',
        'Literature', 'Contemporary', 'Afghanistan', 'Performing Arts', 'Historical', 'Literary, Philosophical and Experimental Fiction', 'Nonfiction and Narrative Nonfiction', 'Friendship', 'War',
    ]], 'novel'],
    'The Duke and I as stored, romance subgenres' => [['provider' => 'hardcover', 'title' => 'The Duke and I', 'pageCount' => 384, 'subjects' => ['Marriage', 'Regency', 'Regency Romance', 'Romance', 'Nonfiction and Narrative Nonfiction', 'Historical Romance', 'Fake Dating']], 'novel'],
    'Thinking, Fast and Slow as stored' => [['provider' => 'hardcover', 'title' => 'Thinking, Fast and Slow', 'pageCount' => 499, 'subjects' => [
        'Educational', 'Education', 'Reflective', 'Informative', 'Personal Development', 'Business Careers', 'Business Economics', 'Decision Making', 'Cognitive Psychology Cognition', 'Nonfiction and Narrative Nonfiction',
    ]], 'nonfiction'],
    'a light novel with a History subject' => [['provider' => 'openlibrary', 'title' => 'Spice and Wolf', 'subjects' => ['Light novels', 'History', 'Fantasy']], 'light-novel'],
]);

test('a nonfiction comic stays a comic', function (array $signals) {
    expect(classified_as($signals))->toBe('graphic-novel');
})->with([
    'Persepolis' => [[
        'provider' => 'openlibrary', 'title' => 'Persepolis', 'deweyCode' => '955', 'pageCount' => 74, 'publishers' => ['Penguin Random House', "L'Association"],
        'subjects' => ['Comic books, strips, etc.', 'Graphic novels', 'History', 'Biography', 'Cartoons and comics', 'COMICS & GRAPHIC NOVELS / Nonfiction', 'BIOGRAPHY & AUTOBIOGRAPHY / Personal Memoirs'],
    ]],
    'March' => [[
        'provider' => 'openlibrary', 'title' => 'March', 'deweyCode' => '328.73', 'pageCount' => 123, 'publishers' => ['Top Shelf Productions'],
        'subjects' => ['Graphic novels', 'history', 'biography', 'African American Nonfiction', 'Young Adult Nonfiction', 'Comic books, strips', 'Personal Memoirs'],
    ]],
    'Fun Home on Hardcover' => [['provider' => 'hardcover', 'title' => 'Fun Home', 'subjects' => ['Graphic Novels', 'Memoir', 'Nonfiction', 'Comics', 'LGBTQ'], 'pageCount' => 232]],
]);

test('manhwa and manhua are told from manga by specific evidence, the generic manga label does not override it', function (array $signals, string $slug) {
    expect(classified_as($signals))->toBe($slug);
})->with([
    'Comic Vine Yen Press edition of a Korean manhwa' => [['provider' => 'comicvine', 'title' => 'Solo Leveling', 'publishers' => ['Yen Press'], 'description' => 'English translation of the Korean manhwa Na Honjaman Level-Up (나 혼자만 레벨업).'], 'manhwa'],
    'Comic Vine French edition with a Hangul alias' => [['provider' => 'comicvine', 'title' => 'Solo Leveling', 'publishers' => ['Delcourt'], 'alternativeTitles' => ['나 혼자만 레벨업']], 'manhwa'],
    'Comic Vine Chinese manhua' => [['provider' => 'comicvine', 'title' => "The King's Avatar", 'publishers' => ['Yen Press'], 'description' => 'English edition of the Chinese manhua Quanzhi Gaoshou.'], 'manhua'],
    'Comic Vine French manga with a kana alias' => [['provider' => 'comicvine', 'title' => 'One Piece', 'publishers' => ['Glénat'], 'alternativeTitles' => ['ワンピース']], 'manga'],
    'a manhwa title marker' => [['provider' => 'comicvine', 'title' => 'Tower of God (Manhwa)'], 'manhwa'],
    'a manhua title marker' => [['provider' => 'hardcover', 'title' => "The King's Avatar (Manhua)"], 'manhua'],
    'BISAC manga umbrella with a Manhwa subject' => [['provider' => 'openlibrary', 'subjects' => ['COMICS & GRAPHIC NOVELS / Manga / Action', 'Manhwa'], 'pageCount' => 300], 'manhwa'],
    'BISAC manga umbrella from a Korean imprint' => [['provider' => 'openlibrary', 'subjects' => ['Comics & graphic novels, manga, fantasy'], 'publishers' => ['Ize Press'], 'pageCount' => 300], 'manhwa'],
    'BISAC manga umbrella with a Korean Dewey class' => [['provider' => 'openlibrary', 'subjects' => ['Comics & graphic novels, manga'], 'publishers' => ['Yen Press'], 'deweyCode' => '741.595195'], 'manhwa'],
    'Hardcover manhwa also tagged manga' => [['provider' => 'hardcover', 'subjects' => ['Manga', 'Manhwa', 'Fantasy'], 'pageCount' => 250], 'manhwa'],
    'Hardcover manhua also tagged manga' => [['provider' => 'hardcover', 'subjects' => ['Manhua', 'Manga'], 'pageCount' => 250], 'manhua'],
    'a Chinese platform imprint' => [['provider' => 'openlibrary', 'subjects' => ['Comics & graphic novels'], 'publishers' => ['Bilibili Comics']], 'manhua'],
    'a Japanese manga whose earliest edition is Korean' => [['provider' => 'openlibrary', 'subjects' => ['Manga', 'Comic books, strips, etc.'], 'publishers' => ['Viz Media', '대원씨아이'], 'language' => 'ko', 'pageCount' => 200], 'manga'],
    'a manga publisher beats the graphic novel default' => [['provider' => 'openlibrary', 'subjects' => ['Graphic novels'], 'publishers' => ['Viz Media'], 'pageCount' => 200], 'manga'],
    'a Western webtoon imprint' => [['provider' => 'openlibrary', 'subjects' => ['Comics & graphic novels'], 'publishers' => ['WEBTOON Unscrolled']], 'webcomic'],
    'a Korean original from a webtoon imprint' => [['provider' => 'openlibrary', 'subjects' => ['Comics & graphic novels'], 'publishers' => ['WEBTOON Unscrolled'], 'language' => 'ko'], 'manhwa'],
]);

test('Japanese, Chinese and Korean names and subjects are read as written, fullwidth included', function (array $signals, string $slug) {
    expect(classified_as(['provider' => 'openlibrary', ...$signals]))->toBe($slug);
})->with([
    'a Dengeki Bunko imprint' => [['publishers' => ['電撃文庫'], 'pageCount' => 300], 'light-novel'],
    'a fullwidth MF Bunko J imprint' => [['publishers' => ['ＭＦ文庫Ｊ'], 'pageCount' => 280], 'light-novel'],
    'a light novel subject in Japanese' => [['subjects' => ['ライトノベル']], 'light-novel'],
    'a light novel subject in Chinese' => [['subjects' => ['輕小說', '奇幻']], 'light-novel'],
    'a webtoon subject in Korean' => [['subjects' => ['웹툰', '판타지'], 'language' => 'ko'], 'manhwa'],
    'a comics subject and a manga house in Japanese' => [['subjects' => ['漫画'], 'publishers' => ['集英社']], 'manga'],
    'a novel subject in Japanese' => [['subjects' => ['小説'], 'pageCount' => 320], 'novel'],
]);

test('stray prose markers on a short book and prose forms in the subjects keep a novel a novel', function (array $signals, string $slug) {
    expect(classified_as(['provider' => 'hardcover', 'creatorRoles' => ['author'], ...$signals]))->toBe($slug);
})->with([
    'a short story collection with a graphic novel subtitle' => [['title' => 'The Yellow Wallpaper', 'alternativeTitles' => ['The Yellow Wallpaper: A Graphic Novel : Unabridged'], 'subjects' => ['Classics', 'Short stories', 'Horror'], 'pageCount' => 24], 'novel'],
    'a novella' => [['title' => 'Of Mice and Men', 'subjects' => ['Classics', 'Historical Fiction', 'Literary Fiction', 'Novella'], 'pageCount' => 162], 'novel'],
    'a long novel with a graphic novel adaptation subtitle' => [['title' => 'Parable of the Sower', 'alternativeTitles' => ['Parable of the Sower: A Graphic Novel Adaptation'], 'subjects' => ['Science Fiction', 'Dystopian', 'Speculative Fiction', 'Classics'], 'pageCount' => 400], 'novel'],
]);

test('board books are picture books, and a picture book with a literature Dewey class stays one', function () {
    expect(classified_as(['provider' => 'openlibrary', 'title' => 'Peekaboo', 'subjects' => ['Board books'], 'pageCount' => 12]))->toBe('picture-book')
        ->and(classified_as([
            'provider' => 'openlibrary', 'title' => 'Goodnight Moon', 'deweyCode' => '816', 'pageCount' => 31,
            'subjects' => ["Children's fiction", 'Picture books', 'Bedtime, fiction', 'Rabbits, fiction', 'Stories in rhyme', 'Juvenile fiction', 'Board books', 'Fiction', 'Classics', "Children's literature, history and criticism", 'Authorship'],
        ]))->toBe('picture-book')
        ->and(classified_as([
            'provider' => 'openlibrary', 'title' => 'Goodnight Moon, as Open Library has it', 'deweyCode' => '816', 'pageCount' => 31, 'publishers' => ['Harper & Row', 'Pan Macmillan'],
            'subjects' => [
                "Children's fiction", 'Picture books', 'Rabbits, fiction', 'Juvenile fiction', 'Board books', 'Fiction', 'Picture books for children', 'Counting books',
                "Children's literature, history and criticism", 'Authorship, juvenile literature', 'Authorship', 'Toy and movable books', 'Short stories', 'Comic books, strips',
            ],
        ]))->toBe('picture-book');
});

test('a fiction or translated literature Dewey class does not turn a picture book into a novel', function (array $signals) {
    expect(classified_as(['provider' => 'openlibrary', 'publishers' => ['HarperCollins'], ...$signals]))->toBe('picture-book');
})->with([
    'The Cat in the Hat' => [['title' => 'The Cat in the Hat', 'deweyCode' => '813.52', 'subjects' => ['Nonsense verses', "Children's stories", 'Juvenile fiction', 'Fiction', 'Stories in rhyme', 'Cats, fiction', 'Picture books']]],
    'Where the Wild Things Are' => [['title' => 'Where the Wild Things Are', 'deweyCode' => '891.6632', 'subjects' => ['Caldecott Medal', 'Fantasy fiction', 'Fiction', 'Juvenile fiction', 'Monsters', 'Fairy tales', "Children's fiction", 'Picture books']]],
]);

test('Sword Art Online as Open Library has it is a light novel: every edition is one, a Yen Audio audiobook among them', function () {
    expect(classified_as([
        'provider' => 'openlibrary', 'title' => 'Sword Art Online', 'pageCount' => 360, 'publishers' => ['TOKYOPOP GmbH', 'Yen Audio', '台灣角川', 'Yen Press'],
        'subjects' => ['Internet games', 'Science fiction', 'Virtual reality', 'Juvenile fiction', 'Fiction', 'Comics & graphic novels, manga, science fiction', 'FICTION / Science Fiction / Adventure', 'Adventure'],
    ]))->toBe('light-novel');
});

test('the new publisher kinds and scripts are normalized like the rest', function () {
    expect(PublisherCatalog::kinds('Ize Press'))->toBe(['manhwa'])
        ->and(PublisherCatalog::kinds('Tappytoon'))->toBe(['manhwa'])
        ->and(PublisherCatalog::kinds('D&C Media'))->toBe(['manhwa'])
        ->and(PublisherCatalog::kinds('Bilibili Comics'))->toBe(['comic', 'manhua'])
        ->and(PublisherCatalog::kinds('WEBTOON Unscrolled'))->toBe(['webcomic'])
        ->and(PublisherCatalog::kinds('集英社'))->toBe(['manga'])
        ->and(PublisherCatalog::kinds('電撃文庫'))->toBe(['light-novel'])
        ->and(PublisherCatalog::kinds('ＭＦ文庫Ｊ'))->toBe(['light-novel'])
        ->and(PublisherCatalog::kinds('디앤씨미디어'))->toBe(['manhwa'])
        ->and(PublisherCatalog::kinds('台灣角川'))->toBe([])
        ->and(PublisherCatalog::kinds('대원씨아이'))->toBe([]);
});

test('Comic Vine says where a translated comic comes from in its blurb', function (array $signals, string $slug) {
    expect(classified_as(['provider' => 'comicvine', ...$signals]))->toBe($slug);
})->with([
    'Akira, Epic\'s English edition' => [['title' => 'Akira', 'publishers' => ['Epic'], 'description' => 'English translation of the Japanese manga Akira, this was one of the first manga published in colour.'], 'manga'],
    'a Japanese title in kanji only' => [['title' => 'Number Five', 'publishers' => ['Viz'], 'description' => 'Proper Japanese Title: 吾 Originally serialized in Spirits Zōkan Ikki.'], 'manga'],
    'the Korean original of Tower of God' => [['title' => 'Sin-ui Tap', 'publishers' => ['Young Com'], 'alternativeTitles' => ['신의 탑', 'Tower of God'], 'description' => 'Proper Korean Title: 신의 탑 (Sin-ui Tap) Originally serialized in Naver Webtoon.'], 'manhwa'],
    'a Chinese title' => [['title' => 'Quanzhi Gaoshou', 'description' => 'Proper Chinese Title: 全职高手. Originally published on Tencent Comics.'], 'manhua'],
    'a French edition that says nothing' => [['title' => 'Blacksad', 'publishers' => ['Dargaud'], 'description' => 'French publication.'], 'comic-book'],
]);
