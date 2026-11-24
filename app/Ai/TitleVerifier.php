<?php

namespace App\Ai;

use App\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lets a model name stories without inventing any: a title is only ever shown when it resolves to a live catalogue entry.
 */
class TitleVerifier
{
    /** The minimum trigram similarity for a near match on a title or a creator name. */
    public const MIN_SIMILARITY = 0.90;

    /** Longest text scrub() looks at; the rest is dropped. */
    private const MAX_SCRUB_CHARS = 20000;

    private const NOT_FOUND = "I couldn't find that in the catalog.";

    /**
     * Lowercased, unaccented, punctuation-free and single-spaced, the same shape the dedup scorer compares (`%s` is the column or parameter).
     */
    private const NORMALIZE = "trim(regexp_replace(regexp_replace(immutable_unaccent(lower(%s)), '[[:punct:]]', ' ', 'g'), '\\s+', ' ', 'g'))";

    /**
     * The catalogue story a title (and creator, when given) names, or null when no such story exists.
     */
    public function resolve(string $title, ?string $creator = null): ?Media
    {
        return $this->resolveMany([['title' => $title, 'creator' => $creator]])[0];
    }

    /**
     * Resolves many mentions in at most three queries; a title matches exactly, by trigram similarity of 0.90 or by a small typo (see tier()) (candidates, winners, creator check).
     *
     * @param  list<array{title: string, creator?: string|null}>  $mentions
     * @return list<Media|null> In the order of the mentions.
     */
    public function resolveMany(array $mentions): array
    {
        $rows = [];
        $bindings = [];

        foreach ($mentions as $index => $mention) {
            $original = trim((string) $mention['title']);
            $clean = $this->clean($original);

            if ($clean === '') {
                continue;
            }

            $rows[] = '(?::int, ?::text, ?::text)';
            array_push($bindings, $index, $clean, $original);
        }

        if ($rows === []) {
            return array_fill(0, count($mentions), null);
        }

        $text = sprintf(self::NORMALIZE, 'v');
        $needle = sprintf(self::NORMALIZE, 'm.clean');

        // The best-matching title or alternative title per media (exact first, then most similar), at most 20 media per mention.
        $found = DB::select(
            "select * from (select m.ord, media.id, media.merged_into_id, media.ratings_count, s.exact, s.sim, s.text, {$needle} as needle,
                row_number() over (partition by m.ord order by s.exact desc, s.sim desc, media.ratings_count desc) as rn
            from (values ".implode(', ', $rows).") as m(ord, clean, original)
            join media on (media.deleted_at is null or media.merged_into_id is not null) and (
                immutable_unaccent(lower(media.title)) % immutable_unaccent(m.clean)
                or immutable_unaccent(lower(media.alternative_titles::text)) %> immutable_unaccent(m.clean)
                or lower(media.title) = lower(m.original)
                or media.alternative_titles @> jsonb_build_array(m.original)
            )
            cross join lateral (
                select {$text} = {$needle} or lower(v) = lower(m.original) as exact, similarity({$text}, {$needle}) as sim, {$text} as text
                from (select media.title as v union all select value from jsonb_array_elements_text(media.alternative_titles)) x
                order by 1 desc, 2 desc limit 1
            ) s) t where rn <= 20",
            $bindings,
        );

        $candidates = [];

        foreach ($found as $row) {
            $tier = $this->tier($row);

            if ($tier !== null) {
                $candidates[$row->ord][] = [$tier, $row];
            }
        }

        $winnerIds = collect($candidates)->flatten(1)->map(fn (array $c): int => (int) ($c[1]->merged_into_id ?? $c[1]->id))->unique()->all();
        $winners = Media::query()->unmerged()->whereIn('id', $winnerIds)->get()->keyBy('id');

        // Best first: exact, then similar, then typo (fewest edits); ties go to the more similar, then the more rated.
        $ranked = [];

        foreach ($candidates as $ord => $rowsForMention) {
            usort($rowsForMention, fn (array $a, array $b): int => [$a[0], -$a[1]->sim, -$a[1]->ratings_count] <=> [$b[0], -$b[1]->sim, -$b[1]->ratings_count]);

            foreach ($rowsForMention as [, $row]) {
                $winner = $winners->get((int) ($row->merged_into_id ?? $row->id));

                if ($winner !== null) {
                    $ranked[$ord][$winner->id] ??= $winner;
                }
            }
        }

        $creatorMatches = $this->creatorMatches($mentions, $ranked);
        $resolved = [];

        foreach ($mentions as $index => $mention) {
            $resolved[] = collect($ranked[$index] ?? [])
                ->first(fn (Media $media): bool => blank($mention['creator'] ?? null) || isset($creatorMatches[$index][$media->id]));
        }

        return $resolved;
    }

    /**
     * The acceptance rank of a candidate (lower is better), or null when it is too far off: 0 exact, 1 similar, then 2+ for a typo, by edit distance.
     * ponytail: typo tolerance is byte-based `levenshtein()`, so it only applies to ASCII titles.
     *
     * @param  object{exact: mixed, sim: mixed, needle: mixed, text: mixed}  $row
     */
    private function tier(object $row): ?int
    {
        return $this->rank((bool) $row->exact, (float) $row->sim, (string) $row->needle, (string) $row->text);
    }

    private function rank(bool $exact, float $sim, string $needle, string $text): ?int
    {
        if ($exact) {
            return 0;
        }

        if ($sim >= self::MIN_SIMILARITY) {
            return 1;
        }

        $length = strlen($needle);

        if ($length < 6 || ! mb_check_encoding($text, 'ASCII') || ! mb_check_encoding($needle, 'ASCII') || abs($length - strlen($text)) > 2) {
            return null;
        }

        $distance = levenshtein($needle, $text);

        return $distance <= ($length <= 12 ? 1 : 2) ? 2 + $distance : null;
    }

    /**
     * Removes every sentence that names a story that is not in `$allowedMedia`, leaving the rest of the text; a list item or paragraph left empty
     * says the story is not in the catalogue. Only quoted titles ("…", “…”, '…', `…`, *…*, _…_, __…__) and the lead of a bulleted item count as mentions.
     *
     * ponytail: only the first 20 000 characters are checked (the rest is dropped, never passed through unverified); sentences split on . ! ? so an abbreviation can over-trim.
     *
     * @param  Collection<int, Media>  $allowedMedia
     */
    public function scrub(string $text, Collection $allowedMedia): string
    {
        $text = mb_substr(trim($text), 0, self::MAX_SCRUB_CHARS);

        if ($text === '') {
            return '';
        }

        $lines = preg_split('/\R/u', $text) ?: [];
        $parsed = [];
        $titles = [];

        foreach ($lines as $line) {
            preg_match('/^(\s*(?:[-*+]|\d+[.)])\s+)?(.*)$/u', $line, $parts);
            $prefix = $parts[1] ?? '';
            $content = $parts[2] ?? '';
            $mentions = $this->mentionsIn($content, $prefix !== '');
            $parsed[] = [$prefix, $content, $mentions];

            foreach ($mentions as $mention) {
                $titles[$mention['title']] = ['title' => $mention['title']];
            }
        }

        $titles = array_values($titles);
        $allowedIds = $allowedMedia->pluck('id')->all();
        $verified = [];

        foreach ($this->resolveMany($titles) as $i => $media) {
            $verified[$titles[$i]['title']] = $media !== null && in_array($media->id, $allowedIds, true);
        }

        $out = [];

        foreach ($parsed as [$prefix, $content, $mentions]) {
            $bad = array_filter($mentions, fn (array $mention): bool => ! $verified[$mention['title']]);

            if ($bad === []) {
                $out[] = $prefix.$content;

                continue;
            }

            $sentences = preg_split('/(?<=[.!?])\s+/u', $content) ?: [];
            $kept = array_filter($sentences, function (string $sentence) use ($bad): bool {
                foreach ($bad as $mention) {
                    if (str_contains($sentence, $mention['raw'])) {
                        return false;
                    }
                }

                return true;
            });

            $out[] = $prefix.($kept === [] ? self::NOT_FOUND : implode(' ', $kept));
        }

        return implode("\n", $out);
    }

    /**
     * The title-like mentions of a line: quoted or emphasised spans, or, for a list item with none, its lead (up to "by", a dash, a colon, a bracket or a comma).
     *
     * @return list<array{title: string, raw: string}>
     */
    private function mentionsIn(string $content, bool $isListItem): array
    {
        preg_match_all('/"([^"\n]{2,150})"|“([^”\n]{2,150})”|\*{1,2}([^*\n]{2,150})\*{1,2}|(?<!\w)__([^_\n]{2,150})__(?!\w)|(?<!\w)_([^_\n]{2,150})_(?!\w)|`([^`\n]{2,150})`|(?<![\w\'])\'([^\'\n]{2,150})\'(?![\w\'])/u', $content, $matches, PREG_SET_ORDER);

        $mentions = [];

        foreach ($matches as $match) {
            $title = trim(implode('', array_slice($match, 1)));
            $mentions[] = ['title' => $title, 'raw' => $match[0]];
        }

        if ($mentions === [] && $isListItem) {
            $lead = trim(preg_split('/\s+(?:by|-|–|—)\s+|[:(,]/u', $content, 2)[0] ?? '');

            if ($lead !== '') {
                $mentions[] = ['title' => $lead, 'raw' => $lead];
            }
        }

        return $mentions;
    }

    /**
     * For each mention that names a creator: which candidate media (by id) credit someone matching that name, exactly or at 0.90 similarity.
     *
     * @param  list<array{title: string, creator?: string|null}>  $mentions
     * @param  array<int, array<int, Media>>  $ranked
     * @return array<int, array<int, true>>
     */
    private function creatorMatches(array $mentions, array $ranked): array
    {
        $rows = [];
        $bindings = [];

        foreach ($mentions as $index => $mention) {
            $creator = $this->clean((string) ($mention['creator'] ?? ''));

            foreach (array_keys($ranked[$index] ?? []) as $mediaId) {
                if ($creator !== '') {
                    $rows[] = '(?::int, ?::bigint, ?::text)';
                    array_push($bindings, $index, $mediaId, $creator);
                }
            }
        }

        if ($rows === []) {
            return [];
        }

        $name = sprintf(self::NORMALIZE, 'creators.name');
        $alt = sprintf(self::NORMALIZE, 'value');
        $needle = sprintf(self::NORMALIZE, 'p.creator');

        $matched = DB::select(
            'select distinct p.ord, p.media_id from (values '.implode(', ', $rows).") as p(ord, media_id, creator)
            join media_creators on media_creators.media_id = p.media_id
            join creators on creators.id = media_creators.creator_id
            where {$name} = {$needle} or similarity({$name}, {$needle}) >= ?
                or exists (select 1 from jsonb_array_elements_text(creators.alternative_names) where {$alt} = {$needle} or similarity({$alt}, {$needle}) >= ?)",
            [...$bindings, self::MIN_SIMILARITY, self::MIN_SIMILARITY],
        );

        $result = [];

        foreach ($matched as $row) {
            $result[$row->ord][$row->media_id] = true;
        }

        return $result;
    }

    /**
     * Strips quotes and punctuation and single-spaces; accents are left for the database's unaccent.
     */
    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{M}\p{N}\s]+/u', ' ', mb_strtolower($value))));
    }
}
