<?php

namespace App\Catalog\Taxonomy;

use App\Enums\WarningStatus;
use App\Models\ContentWarning;
use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes what a source says about content warnings onto a media. A source never erases what another said: a status is reconciled with
 * the stored one (see WarningStatus::reconcile), so a provider reporting a warning an admin verified absent leaves it disputed.
 */
class WarningRecorder
{
    /**
     * @param  list<string>  $slugs  Warning slugs; a slug missing from the vocabulary is created, named after itself.
     * @param  bool  $respectLock  Leave a media whose warnings an admin locked alone (a provider sync does; moving the old list over does not).
     * @return int How many media/warning rows were inserted or changed.
     */
    public function record(Media $media, array $slugs, WarningStatus $status, ?string $source = null, bool $respectLock = true): int
    {
        if ($slugs === [] || ($respectLock && in_array('content_warnings', $media->locked_fields, true))) {
            return 0;
        }

        $existing = DB::table('media_content_warnings')->where('media_id', $media->id)->pluck('status', 'content_warning_id');
        $changed = 0;

        foreach (array_unique($slugs) as $slug) {
            $id = ContentWarning::firstOrCreate(['slug' => $slug], ['name' => Str::headline($slug)])->id;
            $current = $existing->get($id);

            if ($current === null) {
                DB::table('media_content_warnings')->insert(['media_id' => $media->id, 'content_warning_id' => $id, 'status' => $status->value, 'source' => $source, 'created_at' => now(), 'updated_at' => now()]);
                $changed++;

                continue;
            }

            $next = WarningStatus::from($current)->reconcile($status);

            if ($next->value !== $current) {
                DB::table('media_content_warnings')->where('media_id', $media->id)->where('content_warning_id', $id)->update(['status' => $next->value, 'source' => $source, 'updated_at' => now()]);
                $changed++;
            }
        }

        return $changed;
    }
}
