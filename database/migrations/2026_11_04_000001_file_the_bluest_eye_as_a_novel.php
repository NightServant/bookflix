<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Bluest Eye came in from Hardcover as a comic book. Hardcover's record is now refused at import (see ProhibitedContent), so a re-sync can
     * never correct it: it is set to a novel by hand, and the type is locked so `bookflix:reclassify` leaves it alone. Matched by title and primary
     * source, because ids differ between databases; a database without the row (any fresh import) is left as it is.
     */
    public function up(): void
    {
        $hardcover = DB::table('external_sources')->where('key', 'hardcover')->value('id');
        $novel = DB::table('media_types')->where('slug', 'novel')->value('id');

        if ($hardcover === null || $novel === null) {
            return;
        }

        $rows = DB::table('media')->where('title', 'The Bluest Eye')->where('primary_external_source_id', $hardcover)->get(['id', 'locked_fields']);

        foreach ($rows as $row) {
            $locked = json_decode((string) $row->locked_fields, true) ?: [];

            DB::table('media')->where('id', $row->id)->update([
                'media_type_id' => $novel,
                'locked_fields' => json_encode(array_values(array_unique([...$locked, 'media_type_id']))),
                'updated_at' => now(),
            ]);

            DB::table('media_additional_types')->where('media_id', $row->id)
                ->whereIn('media_type_id', DB::table('media_types')->where('family', 'sequential_art')->select('id'))
                ->delete();
        }
    }

    /**
     * A correction: the comic-book type it replaces was wrong, so there is nothing to put back.
     */
    public function down(): void {}
};
