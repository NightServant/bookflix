<?php

namespace App\Catalog\Ingestion;

use App\Catalog\Data\NormalizedMedia;
use Illuminate\Support\Str;

/**
 * The hard rule: sexual content involving minors, and sexualized depictions of minors, is never supported. A record whose provider terms
 * name it is rejected at ingestion, and such a term is never turned into a label (not even a tag).
 */
final class ProhibitedContent
{
    /** Single words, matched as whole words after lowercasing and stripping accents ("Shotaro" is a name, not a match). */
    private const WORDS = ['lolicon', 'loli', 'shotacon', 'shota', 'pedophilia', 'paedophilia', 'pedophile', 'paedophile', 'csam', 'jailbait'];

    /** Phrases, matched on word boundaries. */
    private const PHRASES = ['child porn', 'child pornography', 'child sexual abuse material', 'underage sex', 'sexual content involving minors'];

    public static function matches(string $term): bool
    {
        $words = preg_split('/[^a-z]+/', Str::lower(Str::ascii($term)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (array_intersect($words, self::WORDS) !== []) {
            return true;
        }

        $text = ' '.implode(' ', $words).' ';

        foreach (self::PHRASES as $phrase) {
            if (str_contains($text, " {$phrase} ")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any genre, theme, tag, demographic or content warning term of the record is prohibited.
     */
    public static function inRecord(NormalizedMedia $media): bool
    {
        foreach ([...$media->genres, ...$media->themes, ...$media->tags, ...$media->demographics, ...$media->contentWarnings] as $term) {
            if (self::matches($term)) {
                return true;
            }
        }

        return false;
    }
}
