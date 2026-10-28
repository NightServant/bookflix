<?php

namespace App\Catalog\Classification;

use Illuminate\Support\Str;

/**
 * Decides which media type a provider record is, from everything the record says: the provider's own format field, the title and description, subjects
 * and tags, publishers and imprints, who is credited, page and chapter counts, and the country and language of origin. Pure: no database, no clock.
 *
 * It scores evidence for four families (prose, sequential art, picture book, visual novel), takes the strongest, then picks the form inside it
 * (novel or light novel; manga, manhwa, manhua, webcomic, comic book or graphic novel). Evidence has weights, so a weak signal cannot outvote a strong one:
 *
 * - the provider naming the format (AniList NOVEL, MangaUpdates "Manhwa", Metron "Hardcover") is the strongest;
 * - an explicit marker in the title ("(Novel)", "The Novel", "Novelization", "(Light Novel)") comes next;
 * - publishers, credited roles (penciller, colorist), and comic or prose subjects follow;
 * - a "Graphic Novels" subject on its own is weak, because Open Library and Hardcover also file prose adaptations of comics under it. It counts for more
 *   when the page count looks like a graphic novel, and for little next to a prose publisher, a long page count or a "novelization" blurb;
 * - page counts, chapter counts and the provider's usual output are tie-breakers.
 *
 * Prose wins ties, and a record with no evidence at all is a novel; a comic book is the default only for Comic Vine and Metron, whose records are issue-based
 * comic series, and only while nothing points at prose.
 *
 * Vertical-scroll comics (a MangaDex "Long Strip", a webtoon) follow the country: Korean is manhwa, Chinese manhua, and a Japanese or other work is a webcomic.
 * A "Web Comic" tag alone (published online, format unsaid) leaves Japanese work manga and changes only work from elsewhere.
 */
class MediaTypeClassifier
{
    private const PROSE = 'prose';

    private const COMIC = 'comic';

    private const PICTURE = 'picture';

    private const VISUAL = 'visual';

    /** The provider states the format outright. */
    private const EXPLICIT = 12.0;

    /** The title states the format outright ("Batman: The Novel"). */
    private const TITLE_MARKER = 10.0;

    /** @var array<string, string> Origin country to the comic form it implies. */
    private const ORIGIN_BY_COUNTRY = ['JP' => 'manga', 'KR' => 'manhwa', 'CN' => 'manhua', 'TW' => 'manhua', 'HK' => 'manhua'];

    /** @var array<string, string> Original language to the comic form it implies. */
    private const ORIGIN_BY_LANGUAGE = ['ja' => 'manga', 'ko' => 'manhwa', 'zh' => 'manhua', 'zh-hk' => 'manhua', 'zh-tw' => 'manhua', 'zh-cn' => 'manhua'];

    public function classify(ClassificationSignals $signals): MediaTypeVerdict
    {
        $provider = $signals->provider;
        $format = trim((string) $signals->format);
        $origin = $this->origin($signals);

        if (($unsupported = $this->unsupported($provider, $format)) !== null) {
            return new MediaTypeVerdict('', 1.0, [$unsupported]);
        }

        $family = [self::PROSE => 0.0, self::COMIC => 0.0, self::PICTURE => 0.0, self::VISUAL => 0.0];
        $form = ['novel' => 0.0, 'light-novel' => 0.0, 'manga' => 0.0, 'manhwa' => 0.0, 'manhua' => 0.0, 'webcomic' => 0.0, 'comic-book' => 0.0, 'graphic-novel' => 0.0];
        $flags = ['vertical' => false, 'web' => false, 'webnovel' => false];
        $reasons = [];

        $add = function (string $to, float $weight, string $reason) use (&$family, &$reasons): void {
            $family[$to] += $weight;
            $reasons[] = [$weight, $reason];
        };

        $this->providerFormat($provider, $format, $origin, $signals, $add, $form);
        $this->titleEvidence($signals, $add, $form);
        $this->descriptionEvidence($signals, $add, $form);
        $this->subjectEvidence($signals, $add, $form, $flags);
        $this->publisherEvidence($signals, $add, $form);
        $this->creatorEvidence($signals, $add);
        $this->sizeEvidence($signals, $add, $form);
        $this->prior($provider, $add, $form);

        // MangaDex holds only comics: with no sign of one (a language it takes, a long strip) and no prose tag, there is nothing to classify.
        if ($provider === 'mangadex' && $family[self::COMIC] <= 0.0 && $family[self::PROSE] < 3.0) {
            return new MediaTypeVerdict('', 1.0, ['MangaDex title in a language the catalogue does not take']);
        }

        arsort($family);
        $ranked = array_keys($family);
        $best = $ranked[0];
        $margin = $family[$best] - $family[$ranked[1]];

        // Prose wins a tie, so a record with nothing to go on is a novel.
        if ($margin <= 0.0 && $family[self::PROSE] === $family[$best]) {
            $best = self::PROSE;
        }

        $slug = match ($best) {
            self::PICTURE => 'picture-book',
            self::VISUAL => 'visual-novel',
            self::PROSE => $this->proseForm($form, $flags, $signals, $origin),
            default => $this->comicForm($form, $flags, $origin, $provider, $signals),
        };

        if ($slug === '') {
            return new MediaTypeVerdict('', 1.0, ['a comic from a country the catalogue does not take']);
        }

        usort($reasons, fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return new MediaTypeVerdict(
            $slug,
            round(min(0.99, 0.5 + 0.5 * (1 - exp(-max($margin, 0.0) / 5))), 2),
            array_map(fn (array $reason): string => $reason[1], array_slice($reasons, 0, 5)),
        );
    }

    /**
     * Records that are no kind of work the catalogue holds: a format the provider names that is not a book, or a comic whose origin the provider does not give.
     */
    private function unsupported(string $provider, string $format): ?string
    {
        return match ($provider) {
            'anilist' => in_array(Str::upper($format), ['MANGA', 'ONE_SHOT', 'NOVEL'], true) ? null : "AniList format {$format} is not a book",
            'mangaupdates' => in_array(Str::lower($format), ['manga', 'manhwa', 'manhua', 'novel'], true) ? null : "MangaUpdates type {$format} is not a book",
            default => null,
        };
    }

    /**
     * What the provider's format field settles.
     *
     * @param  callable(string, float, string): void  $add
     * @param  array<string, float>  $form
     */
    private function providerFormat(string $provider, string $format, ?string $origin, ClassificationSignals $signals, callable $add, array &$form): void
    {
        $type = Str::lower($format);

        switch ($provider) {
            case 'anilist':
                if (Str::upper($format) === 'NOVEL') {
                    $add(self::PROSE, self::EXPLICIT, 'AniList format NOVEL');
                    $form['light-novel'] += self::EXPLICIT;

                    break;
                }

                $add(self::COMIC, self::EXPLICIT, 'AniList format '.Str::upper($format));

                if ($origin !== null) {
                    $form[$origin] += self::EXPLICIT;
                }

                break;

            case 'mangaupdates':
                if ($type === 'novel') {
                    $add(self::PROSE, self::EXPLICIT, 'MangaUpdates type Novel');
                    $form['light-novel'] += self::EXPLICIT;

                    break;
                }

                $add(self::COMIC, self::EXPLICIT, "MangaUpdates type {$format}");
                $form[$type] += self::EXPLICIT;

                break;

            case 'mangadex':
                // MangaDex holds comics, in the original language's form; a prose tag below can still outvote this.
                if ($origin !== null) {
                    $add(self::COMIC, 6.0, "MangaDex original language {$signals->language}");
                    $form[$origin] += self::EXPLICIT;
                }

                break;

            case 'metron':
                $collected = preg_match('/hard ?cover|trade paperback|\btpb\b|graphic novel|omnibus|collect|compendium|deluxe|library/', $type) === 1;
                $add(self::COMIC, 1.0, "Metron series type {$format}");
                $form[$collected ? 'graphic-novel' : 'comic-book'] += $collected ? 4.0 : 3.0;

                break;
        }
    }

    /**
     * @param  callable(string, float, string): void  $add
     * @param  array<string, float>  $form
     */
    private function titleEvidence(ClassificationSignals $signals, callable $add, array &$form): void
    {
        foreach ([[$signals->title, 1.0], ...array_map(fn (string $title): array => [$title, 0.4], $signals->alternativeTitles)] as [$raw, $scale]) {
            $title = $this->fold($raw);

            if ($title === '') {
                continue;
            }

            $lightNovel = preg_match('/\blight novels?\b|\branobe\b/', $title) === 1;
            $webNovel = preg_match('/\bweb novels?\b/', $title) === 1;
            $novel = preg_match('/\((?:an? |the )?novel\)|[:\-,]\s*(?:an? |the )novel\s*$|\bnovelizations?\b|\bnovelisations?\b|\(prose\)|\bprose (?:novel|edition)\b/', $title) === 1;

            if ($novel || $lightNovel || $webNovel) {
                $add(self::PROSE, ($novel ? self::TITLE_MARKER : 8.0) * $scale, 'title says novel');
            }

            if ($lightNovel || $webNovel) {
                $form['light-novel'] += 8.0 * $scale;
            }

            if (preg_match('/\bgraphic novels?\b/', $title) === 1) {
                $add(self::COMIC, 6.0 * $scale, 'title says graphic novel');
                $form['graphic-novel'] += 4.0 * $scale;
            }

            if (preg_match('/\b(?:omnibus|compendium|deluxe edition|library edition|hardcover|trade paperback|tpb|collected edition)\b/', $title) === 1) {
                $add(self::COMIC, 2.0 * $scale, 'collected edition title');
                $form['graphic-novel'] += 3.0 * $scale;
            }

            if (preg_match('/#\s?\d+|\bissue\b/', $title) === 1) {
                $form['comic-book'] += 3.0 * $scale;
            }

            if (preg_match('/\(manga\)|\(manhwa\)|\(manhua\)|\(webtoon\)/', $title) === 1) {
                $add(self::COMIC, 6.0 * $scale, 'title says comic');
            }

            if (preg_match('/\bvisual novel\b|\(vn\)/', $title) === 1) {
                $add(self::VISUAL, 4.0 * $scale, 'title says visual novel');
            }
        }
    }

    /**
     * What the description says the work is. A comic described as an adaptation of a novel or a visual novel is still a comic.
     *
     * @param  callable(string, float, string): void  $add
     * @param  array<string, float>  $form
     */
    private function descriptionEvidence(ClassificationSignals $signals, callable $add, array &$form): void
    {
        $text = $this->fold(mb_substr((string) $signals->description, 0, 600));

        if ($text === '') {
            return;
        }

        $adapts = '/adapt|based on|manga|manhwa|comic|spin ?off|sequel/';

        $novelization = false;
        $plain = fn (string $words): bool => preg_match('/graphic|visual|light|web|comic|manga/', $words) === 0;

        if (preg_match('/\b(?:novelizations?|novelisations?|prose (?:novel|adaptation|edition|retelling))\b/', $text) === 1) {
            $add(self::PROSE, 6.0, 'description says novelization');
            $novelization = true;
        } elseif ((preg_match('/\b(?:is|was) an? ((?:[a-z0-9\'-]+ ){0,4}?)novel\b/', $text, $match) === 1 && $plain($match[1]))
            || (preg_match('/^(?:an? |the )?((?:[a-z0-9\'-]+ ){0,3}?)novel\b/', $text, $match) === 1 && $plain($match[1]))) {
            $add(self::PROSE, 3.0, 'description calls it a novel');
        }

        if (preg_match('/\b(?:is|was) an? ((?:[a-z0-9\'-]+ ){0,4}?)light novel\b/', $text, $match) === 1 && preg_match($adapts, $match[0]) === 0) {
            $add(self::PROSE, 4.0, 'description calls it a light novel');
            $form['light-novel'] += 4.0;
        }

        if (preg_match('/\b(?:is|was) an? ((?:[a-z0-9\'-]+ ){0,4}?)visual novel\b/', $text, $match) === 1 && preg_match($adapts, $match[0]) === 0) {
            $add(self::VISUAL, 6.0, 'description calls it a visual novel');
        }

        if (preg_match('/\bpicture book\b/', $text) === 1) {
            $add(self::PICTURE, 3.0, 'description says picture book');
        }

        // "The novelization of the comic book storyline" is about a comic, not one.
        if (! $novelization && preg_match('/\b(?:graphic novel|comic book|comic series|webtoon|manga series)\b/', $text) === 1) {
            $add(self::COMIC, 1.5, 'description mentions comics');
        }
    }

    /**
     * @param  callable(string, float, string): void  $add
     * @param  array<string, float>  $form
     * @param  array{vertical: bool, web: bool, webnovel: bool}  $flags
     */
    private function subjectEvidence(ClassificationSignals $signals, callable $add, array &$form, array &$flags): void
    {
        $subjects = array_values(array_filter(array_map($this->phrase(...), $signals->subjects)));
        $has = fn (array $needles): bool => array_filter($subjects, fn (string $subject): bool => in_array($subject, $needles, true)) !== [];
        $pages = $signals->pageCount;

        if ($has(['comic books strips etc', 'comics', 'comic books', 'comic book', 'comic strips', 'comics and graphic novels', 'cartoons and comics'])) {
            $add(self::COMIC, 3.5, 'comics subject');
            $form['comic-book'] += $has(['comics', 'comic books', 'comic book']) ? 1.5 : 0.0;
        }

        if ($has(['graphic novels', 'graphic novel'])) {
            // Prose adaptations of comics are filed under this too, so on its own it is weak; a graphic novel sized book makes it more likely.
            $add(self::COMIC, 1.5 + ($pages !== null && $pages >= 24 && $pages <= 320 ? 1.5 : 0.0), 'graphic novels subject');
            $form['graphic-novel'] += 1.0;
        }

        foreach (['manga' => 'manga', 'manhwa' => 'manhwa', 'manhua' => 'manhua'] as $word => $slug) {
            if ($has([$word, $word.'s'])) {
                $add(self::COMIC, 5.0, "{$word} subject");
                $form[$slug] += 5.0;
            }
        }

        if ($has(['webtoon', 'webtoons', 'webcomic', 'webcomics', 'web comic', 'web comics'])) {
            $add(self::COMIC, 3.0, 'webcomic subject');
            $flags['web'] = true;
        }

        if ($has(['long strip'])) {
            $add(self::COMIC, 3.0, 'long strip format');
            $flags['vertical'] = true;
        }

        if ($has(['light novel', 'light novels', 'ranobe'])) {
            $add(self::PROSE, $signals->provider === 'mangadex' ? 8.0 : 6.0, 'light novel subject');
            $form['light-novel'] += 6.0;
        }

        if ($has(['web novel', 'web novels'])) {
            $add(self::PROSE, $signals->provider === 'mangadex' ? 8.0 : 6.0, 'web novel subject');
            $flags['webnovel'] = true;
        }

        if ($has(['novel', 'novels'])) {
            $add(self::PROSE, 3.0, 'novel subject');
        }

        $fiction = count(array_filter($subjects, fn (string $subject): bool => $subject === 'fiction' || str_ends_with($subject, ' fiction') || str_ends_with($subject, ' stories')));

        if ($fiction > 0) {
            $add(self::PROSE, min(1.5, $fiction * 0.5), 'fiction subjects');
        }

        if ($has(['visual novel', 'visual novels'])) {
            // A manga or comic filed under "Visual novels" adapts one; it is not one.
            $adapts = $has(['manga', 'manhwa', 'manhua', 'comics', 'comic books', 'graphic novels']);
            $add(self::VISUAL, $adapts ? 2.0 : 8.0, 'visual novel subject');
        }

        if ($has(['picture book', 'picture books', 'picture books for children'])) {
            $add(self::PICTURE, 6.0, 'picture book subject');
        }
    }

    /**
     * @param  callable(string, float, string): void  $add
     * @param  array<string, float>  $form
     */
    private function publisherEvidence(ClassificationSignals $signals, callable $add, array &$form): void
    {
        $kinds = array_unique(array_merge(...array_map(PublisherCatalog::kinds(...), $signals->publishers ?: [''])));

        foreach ($kinds as $kind) {
            switch ($kind) {
                case PublisherCatalog::LIGHT_NOVEL:
                    $add(self::PROSE, 7.0, 'light novel imprint');
                    $form['light-novel'] += 7.0;

                    break;
                case PublisherCatalog::MANGA:
                    $add(self::COMIC, 4.0, 'manga publisher');
                    $form['manga'] += 3.0;

                    break;
                case PublisherCatalog::COMIC:
                    $add(self::COMIC, 4.0, 'comic publisher');

                    break;
                case PublisherCatalog::PROSE:
                    $add(self::PROSE, 3.0, 'prose publisher');

                    break;
            }
        }
    }

    /**
     * @param  callable(string, float, string): void  $add
     */
    private function creatorEvidence(ClassificationSignals $signals, callable $add): void
    {
        $roles = array_map(fn (string $role): string => Str::lower(trim($role)), $signals->creatorRoles);

        if (array_intersect($roles, ['penciller', 'penciler', 'inker', 'colorist', 'colourist', 'letterer']) !== []) {
            $add(self::COMIC, 4.0, 'comic credits');
        } elseif ($roles !== [] && array_diff($roles, ['author', 'translator', 'editor', 'narrator', 'foreword', 'introduction']) === []) {
            $add(self::PROSE, 1.0, 'only prose credits');
        } elseif (array_intersect($roles, ['writer', 'artist', 'illustrator']) !== []) {
            $add(self::COMIC, 0.5, 'writer and artist credits');
        }
    }

    /**
     * @param  callable(string, float, string): void  $add
     * @param  array<string, float>  $form
     */
    private function sizeEvidence(ClassificationSignals $signals, callable $add, array &$form): void
    {
        $pages = $signals->pageCount;

        if ($pages !== null && $pages > 0) {
            if ($pages >= 450) {
                $add(self::PROSE, 3.0, "{$pages} pages");
            } elseif ($pages >= 300) {
                $add(self::PROSE, 2.0, "{$pages} pages");
            } elseif ($pages >= 200) {
                $add(self::PROSE, 0.5, "{$pages} pages");
            } elseif ($pages <= 64) {
                $add(self::COMIC, 1.5, "{$pages} pages");
                $form['comic-book'] += 2.0;
            }
        }

        if ($signals->chapterCount !== null && $signals->chapterCount > 0 && $pages === null) {
            $add(self::COMIC, 0.5, 'serial chapters and no page count');
        }
    }

    /**
     * What each provider mostly publishes, as the thinnest evidence: Comic Vine and Metron list issue-based comic series, the book catalogues mostly list prose.
     *
     * @param  callable(string, float, string): void  $add
     * @param  array<string, float>  $form
     */
    private function prior(string $provider, callable $add, array &$form): void
    {
        if (in_array($provider, ['comicvine', 'metron'], true)) {
            $add(self::COMIC, 3.5, 'issue-based comic series');
            $form['comic-book'] += 2.0;
        } elseif (in_array($provider, ['openlibrary', 'hardcover'], true)) {
            $add(self::PROSE, 1.0, 'a book catalogue');
            $form['graphic-novel'] += 2.0;
        }
    }

    /**
     * A light novel when the evidence says so (an Asian web novel counts as one); everything else prose is a novel.
     *
     * @param  array<string, float>  $form
     * @param  array{vertical: bool, web: bool, webnovel: bool}  $flags
     */
    private function proseForm(array $form, array $flags, ClassificationSignals $signals, ?string $origin): string
    {
        $asian = $origin !== null || in_array(Str::upper((string) $signals->country), ['JP', 'KR', 'CN', 'TW', 'HK'], true);

        return $form['light-novel'] >= 3.0 || ($flags['webnovel'] && $asian) ? 'light-novel' : 'novel';
    }

    /**
     * The form of a comic. Vertical-scroll work follows the country; otherwise the strongest form evidence wins, and a tie goes to what the provider
     * usually lists: single issues for Comic Vine and Metron, bound books for the book catalogues.
     *
     * @param  array<string, float>  $form
     * @param  array{vertical: bool, web: bool, webnovel: bool}  $flags
     */
    private function comicForm(array $form, array $flags, ?string $origin, string $provider, ClassificationSignals $signals): string
    {
        if ($origin !== null) {
            $form[$origin] += 2.0;
        }

        if ($flags['vertical'] || $flags['web']) {
            return match (true) {
                $origin === 'manhwa', $origin === 'manhua' => $origin,
                $origin === 'manga' => $flags['vertical'] ? 'webcomic' : 'manga',
                default => 'webcomic',
            };
        }

        $candidates = ['manga' => $form['manga'], 'manhwa' => $form['manhwa'], 'manhua' => $form['manhua'], 'comic-book' => $form['comic-book'], 'graphic-novel' => $form['graphic-novel']];

        // AniList and MangaDex say nothing about the form beyond the country: one the catalogue does not take (not JP, KR, CN, TW) is skipped.
        if (in_array($provider, ['anilist', 'mangadex'], true) && $origin === null) {
            return '';
        }

        $default = in_array($provider, ['comicvine', 'metron'], true) ? 'comic-book' : 'graphic-novel';
        $best = max($candidates);
        $leaders = array_keys($candidates, $best, true);

        if ($best <= 0.0 || in_array($default, $leaders, true)) {
            return $default;
        }

        return $leaders[0];
    }

    /**
     * The comic form the record's origin implies, by country first, then language.
     */
    private function origin(ClassificationSignals $signals): ?string
    {
        return self::ORIGIN_BY_COUNTRY[Str::upper((string) $signals->country)]
            ?? self::ORIGIN_BY_LANGUAGE[Str::lower((string) $signals->language)]
            ?? null;
    }

    /**
     * Lowercase with accents folded and whitespace collapsed: titles and descriptions as the patterns read them.
     */
    private function fold(?string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii((string) $text))) ?? '');
    }

    /**
     * A subject or tag as a whole phrase: lowercase, accents folded, punctuation as single spaces ("Comic books, strips, etc." is `comic books strips etc`).
     */
    private function phrase(string $term): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($term))) ?? '');
    }
}
