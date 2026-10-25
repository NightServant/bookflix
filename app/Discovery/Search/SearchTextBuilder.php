<?php

namespace App\Discovery\Search;

use App\Models\Media;
use Illuminate\Support\Str;
use Transliterator;

class SearchTextBuilder
{
    /**
     * Relations whose names make a media findable.
     *
     * @var list<string>
     */
    public const RELATIONS = ['creators', 'series', 'genres', 'themes', 'tags', 'characters', 'publishers'];

    /**
     * Every name that can find the media, lowercased, followed by an accent-folded copy so `Pokemon` finds `Pokémon`.
     * CJK text is left as it is.
     */
    public function build(Media $media): string
    {
        $media->loadMissing(self::RELATIONS);

        $terms = array_merge(
            [$media->title],
            $media->alternative_titles,
            $media->creators->pluck('name')->all(),
            $media->series->pluck('title')->all(),
            $media->genres->pluck('name')->all(),
            $media->themes->pluck('name')->all(),
            $media->tags->pluck('name')->all(),
            $media->characters->pluck('name')->all(),
            $media->publishers->pluck('name')->all(),
        );

        $text = Str::lower(implode(' ', array_unique(array_filter($terms))));
        $folded = Transliterator::create('NFC; [:Latin:] Latin-ASCII')?->transliterate($text) ?: $text;

        return $folded === $text ? $text : "{$text} {$folded}";
    }
}
