<?php

namespace App\Reviews;

/**
 * The short plain text kept of an outside review: no markup, one line of single spaces, at most 300 characters, cut at a word.
 */
final class Excerpt
{
    public const MAX = 300;

    public static function make(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('~<br\s*/?>|</p>~i', ' ', $text) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = trim(preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $text) ?? '');

        if ($text === '' || ! mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        if (mb_strlen($text) <= self::MAX) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::MAX - 1);

        // Back to the last whole word, unless the cut already fell on a space.
        if (mb_substr($text, self::MAX - 1, 1) !== ' ' && ($space = mb_strrpos($cut, ' ')) !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }

        $cut = rtrim($cut, " \t,.;:-–—");

        return $cut === '' ? null : $cut.'…';
    }
}
