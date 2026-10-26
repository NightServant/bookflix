<?php

namespace App\Classification\Dewey;

use App\Classification\ClassificationCandidate;
use App\Enums\ClassificationMethod;
use App\Enums\MediaFamily;
use App\Models\Media;
use Illuminate\Support\Str;

class DeweyMapper
{
    /** Literature class by country of origin. */
    private const LITERATURE = ['US' => '813', 'GB' => '823', 'DE' => '833', 'FR' => '843', 'IT' => '853', 'ES' => '863', 'RU' => '891.7', 'CN' => '895.1', 'JP' => '895.6', 'KR' => '895.7'];

    /** Comics class by country of origin; any other country gets 741.59. */
    private const COMICS = ['JP' => '741.52', 'KR' => '741.53', 'CN' => '741.54', 'TW' => '741.54', 'US' => '741.55', 'GB' => '741.55', 'CA' => '741.55', 'FR' => '741.56', 'BE' => '741.56'];

    /** The country a prose work most likely comes from when only its language is known. */
    private const COUNTRY_BY_LANGUAGE = ['en' => 'US', 'ja' => 'JP', 'ko' => 'KR', 'zh' => 'CN', 'fr' => 'FR', 'de' => 'DE', 'it' => 'IT', 'es' => 'ES', 'ru' => 'RU'];

    /** @var array<string, string>|null Subject keyword to class code, from `database/data/dewey_subject_keywords.csv`. */
    private ?array $keywords = null;

    /**
     * The rule-based candidates for a media, best guess first. They are suggestions only: nothing is stored here and the
     * validator trims them to the limits.
     *
     * @return list<ClassificationCandidate>
     */
    public function map(Media $media): array
    {
        $media->loadMissing(['mediaType', 'genres', 'themes', 'tags']);

        $candidates = match ($media->mediaType->family) {
            MediaFamily::Prose => [$this->literature($media, $media->genres->isNotEmpty() ? 0.85 : 0.75)],
            MediaFamily::Interactive => [$this->literature($media, 0.70)],
            MediaFamily::SequentialArt, MediaFamily::Illustrated => $this->comics($media),
        };

        return [...$candidates, ...$this->subjects($media)];
    }

    /**
     * The primary comics class by country, plus a literature class when the work is literary.
     *
     * @return list<ClassificationCandidate>
     */
    private function comics(Media $media): array
    {
        $candidates = [$this->candidate(self::COMICS[$this->country($media)] ?? '741.59', 0.80)];

        if ($media->mediaType->slug === 'graphic-novel' || $media->genres->contains('slug', 'literary-fiction')) {
            $candidates[] = $this->literature($media, 0.70);
        }

        return $candidates;
    }

    /**
     * The literature class for the work's country, else its language; class 800 at 0.70 when neither is known.
     */
    private function literature(Media $media, float $confidence): ClassificationCandidate
    {
        $country = $this->country($media);
        $language = self::COUNTRY_BY_LANGUAGE[Str::lower(Str::before((string) $media->language, '-'))] ?? '';
        $code = self::LITERATURE[$country] ?? self::LITERATURE[$language] ?? null;

        return $this->candidate($code ?? '800', $code === null ? 0.70 : $confidence);
    }

    /**
     * Secondary classes for subject keywords in the genre, theme and tag names (whole name) and in the description (single words).
     *
     * @return list<ClassificationCandidate>
     */
    private function subjects(Media $media): array
    {
        $names = [...$media->genres->pluck('name'), ...$media->themes->pluck('name'), ...$media->tags->pluck('name')];
        $words = preg_split('/[^\p{L}\p{N}]+/u', Str::lower((string) $media->description), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $keywords = $this->keywords();
        $codes = [];

        foreach ([...array_map(fn (string $name) => Str::lower(trim($name)), $names), ...$words] as $term) {
            if (isset($keywords[$term])) {
                $codes[] = $keywords[$term];
            }
        }

        return array_map(fn (string $code) => $this->candidate($code, 0.70), array_values(array_unique($codes)));
    }

    private function country(Media $media): string
    {
        return Str::upper(trim((string) $media->country_of_origin));
    }

    private function candidate(string $code, float $confidence): ClassificationCandidate
    {
        return new ClassificationCandidate($code, $confidence, ClassificationMethod::Automatic, 'rules');
    }

    /**
     * @return array<string, string>
     */
    private function keywords(): array
    {
        if ($this->keywords !== null) {
            return $this->keywords;
        }

        $lines = array_slice(file(database_path('data/dewey_subject_keywords.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], 1);
        $this->keywords = [];

        foreach ($lines as $line) {
            [$keyword, $code] = str_getcsv($line, escape: '');
            $this->keywords[Str::lower(trim($keyword))] = trim($code);
        }

        return $this->keywords;
    }
}
