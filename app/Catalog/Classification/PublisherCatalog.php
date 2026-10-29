<?php

namespace App\Catalog\Classification;

use Illuminate\Support\Str;

/**
 * What a publisher or imprint tells about the kind of book it puts out. Only names that are reliable evidence are listed: houses that publish
 * both prose and comics under one name (Kodansha, Kadokawa, Del Rey, Scholastic, Titan) are left out unless an imprint settles it, and so are
 * Korean and Chinese houses that also print translated manga (Daewon, Haksan): only the platforms and imprints of Korean and Chinese originals count.
 * Japanese, Chinese and Korean names are matched as written (fullwidth folded), since transliteration turns 電撃文庫 into nothing recognisable.
 */
final class PublisherCatalog
{
    public const COMIC = 'comic';

    public const MANGA = 'manga';

    public const MANHWA = 'manhwa';

    public const MANHUA = 'manhua';

    /** Webtoon platforms that publish Western and Korean originals alike: the format is the vertical strip, the country is not said. */
    public const WEBCOMIC = 'webcomic';

    public const LIGHT_NOVEL = 'light-novel';

    public const PROSE = 'prose';

    /**
     * Normalized names (see normalize()). A name of two or more words matches anywhere in a publisher's name, a one word name only the whole name,
     * so "Image" is not found inside "Image Comics Group" by accident but "dark horse" is found in "Dark Horse Comics". A name in another script
     * matches anywhere in the name as written.
     *
     * @var array<string, list<string>>
     */
    private const NAMES = [
        self::LIGHT_NOVEL => [
            'yen on', 'j novel club', 'seven seas airship', 'airship', 'dengeki bunko', 'ga bunko', 'mf bunko', 'mf bunko j', 'fujimi fantasia bunko',
            'kadokawa sneaker bunko', 'sneaker bunko', 'hj bunko', 'overlap bunko', 'gagaga bunko', 'dash x bunko', 'shueisha dash x bunko',
            'jump j books', 'kodansha ranobe bunko', 'ranobe bunko', 'famitsu bunko', 'cross infinite world', 'sol press', 'hanashi media', 'yen audio',
            '電撃文庫', 'mf文庫j', 'ファンタジア文庫', 'スニーカー文庫', 'ga文庫', 'hj文庫', 'オーバーラップ文庫', 'ガガガ文庫', 'ダッシュエックス文庫', 'ラノベ文庫',
            'ファミ通文庫', '轻小说', '輕小說', '라이트노벨',
        ],
        self::MANGA => [
            'viz media', 'viz', 'shueisha', 'shogakukan', 'kodansha comics', 'kodansha manga', 'kodansha usa', 'square enix manga', 'yen press',
            'seven seas entertainment', 'tokyopop', 'denpa', 'vertical comics', 'dark horse manga', 'udon entertainment', 'hakusensha', 'akita shoten',
            'futabasha', 'shonen jump', 'jump comics', 'ichijinsha', 'mag garden', 'j novel heart', 'square enix', 'comikey', 'azuki', 'inklore',
            '集英社', '小学館', '白泉社', '秋田書店', '双葉社', '一迅社', 'マッグガーデン', 'スクウェア・エニックス',
        ],
        self::MANHWA => [
            'ize press', 'tappytoon', 'lezhin', 'lezhin comics', 'lezhin entertainment', 'toomics', 'kakao entertainment', 'kakaopage', 'kakao page',
            'd and c media', 'd and c webtoon biz', 'redice studio', 'manta comics', 'naver webtoon', '카카오페이지', '카카오엔터테인먼트', '디앤씨미디어',
            '레진코믹스', '네이버웹툰', '투믹스',
        ],
        self::MANHUA => [
            'bilibili', 'bilibili comics', 'tencent comics', 'tencent animation and comics', 'kuaikan', 'kuaikan manhua', '哔哩哔哩', '腾讯动漫', '快看漫画',
        ],
        self::WEBCOMIC => ['webtoon', 'webtoon unscrolled', 'line webtoon', 'tapas', 'tapas media', 'tapas entertainment'],
        self::COMIC => [
            'marvel', 'dc comics', 'dc', 'image', 'image comics', 'dark horse', 'idw', 'idw publishing', 'boom', 'boom studios', 'dynamite', 'valiant',
            'archie comics', 'oni press', 'vertigo', 'wildstorm', 'fantagraphics', 'drawn and quarterly', 'top shelf', 'aftershock', 'humanoids',
            'papercutz', 'first second', 'graphix', 'abrams comicarts', 'titan comics', 'zenescope', 'antarctic press', 'avatar press', '2000 ad',
            'rebellion', 'nbm', 'iron circus', 'lion forge', 'scout comics', 'vault comics', 'mad cave', 'source point press', 'ahoy comics',
            'action lab', 'blackbird comics', 'comixology originals', 'dc black label', 'milestone',
        ],
        self::PROSE => [
            'penguin', 'penguin books', 'penguin random house', 'random house', 'harpercollins', 'harper collins', 'harper voyager', 'tor', 'tor books',
            'simon and schuster', 'macmillan', 'pan macmillan', 'hachette', 'orbit', 'pocket books', 'bantam', 'bantam spectra', 'ace', 'ace books',
            'daw books', 'baen', 'gollancz', 'bloomsbury', 'vintage', 'anchor books', 'knopf', 'doubleday', 'little brown', 'houghton mifflin',
            'st martin s press', 'berkley', 'avon', 'harlequin', 'mills and boon', 'del rey books', 'aconyte', 'black library', 'wizards of the coast',
            'sourcebooks', 'angry robot', 'solaris', 'william morrow', 'scribner', 'grand central', 'atria', 'minotaur', 'hodder', 'headline',
            'faber', 'oxford university press', 'cambridge university press', 'puffin', 'scholastic press',
        ],
    ];

    /**
     * The kinds a publisher name points at (a name can match more than one list; light novel imprints are checked first).
     *
     * @return list<string>
     */
    public static function kinds(string $publisher): array
    {
        $name = self::normalize($publisher);
        $written = self::written($publisher);

        if ($name === '' && $written === '') {
            return [];
        }

        $kinds = [];

        if (preg_match('/\b(ranobe|light novels?)\b/', $name) === 1) {
            $kinds[] = self::LIGHT_NOVEL;
        }

        if (preg_match('/\b(comics|manga|comic books?)\b/', $name) === 1) {
            $kinds[] = str_contains($name, 'manga') ? self::MANGA : self::COMIC;
        }

        foreach (self::NAMES as $kind => $names) {
            foreach ($names as $known) {
                $found = preg_match('/[^\x00-\x7F]/', $known) === 1
                    ? str_contains($written, $known)
                    : $name === $known || (str_contains($known, ' ') && str_contains(" {$name} ", " {$known} "));

                if ($found) {
                    $kinds[] = $kind;
                    break;
                }
            }
        }

        return array_values(array_unique($kinds));
    }

    /**
     * Lowercase, accents folded, `&` as "and", punctuation as spaces, corporate suffixes dropped.
     */
    public static function normalize(string $name): string
    {
        $text = Str::lower(Str::ascii(str_replace('&', ' and ', $name)));
        $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_values(array_diff($words, ['inc', 'llc', 'ltd', 'co', 'corp', 'company', 'the'])));
    }

    /**
     * The name as written, lowercase with fullwidth forms folded (ＭＦ文庫Ｊ is `mf文庫j`), for the names in other scripts.
     */
    public static function written(string $name): string
    {
        return mb_strtolower(trim((string) \Normalizer::normalize($name, \Normalizer::FORM_KC)));
    }
}
