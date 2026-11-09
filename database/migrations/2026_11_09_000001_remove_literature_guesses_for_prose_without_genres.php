<?php

use App\Classification\DeweyCounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The literature classes the mapper picks by country or language (DeweyMapper::LITERATURE). */
    private const LITERATURE = ['813', '823', '833', '843', '853', '863', '891.7', '895.1', '895.6', '895.7'];

    /**
     * Until 2026-11-09 the Dewey mapper gave prose with no genres a literature class from its country or edition language at 0.75, so untagged
     * treatises, tafsir volumes and coin catalogues with English editions sat in 813 American Fiction. The mapper no longer makes that guess,
     * and the rows it made are deleted here whatever their review status. A rule-based row at 0.75 was only ever that guess (prose with genres
     * gets 0.85, every other rule 0.70 or 0.80); rows on media that has gained a genre since, and provider-code, keyword and admin rows, stay.
     */
    public function up(): void
    {
        $ids = DB::table('media_dewey_classes')
            ->join('media', 'media.id', '=', 'media_dewey_classes.media_id')
            ->join('media_types', 'media_types.id', '=', 'media.media_type_id')
            ->join('dewey_classes', 'dewey_classes.id', '=', 'media_dewey_classes.dewey_class_id')
            ->where('media_dewey_classes.source', 'rules')
            ->where('media_dewey_classes.classification_method', 'automatic')
            ->where('media_dewey_classes.confidence', 0.75)
            ->where('media_types.family', 'prose')
            ->whereIn('dewey_classes.code', self::LITERATURE)
            ->whereNotExists(fn (Builder $genres) => $genres->from('media_genres')->whereColumn('media_genres.media_id', 'media_dewey_classes.media_id'))
            ->pluck('media_dewey_classes.id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('media_dewey_classes')->whereIn('id', $ids)->delete();
        app(DeweyCounts::class)->flush();
    }

    /**
     * The rows were wrong guesses; the classification job can only add classes the current mapper suggests.
     */
    public function down(): void {}
};
