<?php

use App\Catalog\Taxonomy\Vocabulary;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A genre is mature-gated only when its PRIMARY parent is the mature family (or it is that family). Part of the taxonomy first flagged
     * every genre the mature section listed, so "Betrayal" (Drama) and "Dark Romance" (Romance) vanished from their own families.
     * A genre listed first elsewhere is visible again; the mature family still lists it through its extra parent link while that family is visible.
     * A data step on the stored rows, so a database that was seeded earlier is corrected without a re-seed. A database without the family has nothing to change.
     */
    public function up(): void
    {
        $family = DB::table('genres')->where('slug', Vocabulary::MATURE_FAMILY)->value('id');

        if ($family === null) {
            return;
        }

        DB::table('genres')->where('is_mature', true)->where('id', '!=', $family)
            ->where(fn ($genres) => $genres->whereNull('parent_id')->orWhere('parent_id', '!=', $family))
            ->update(['is_mature' => false]);
        DB::table('genres')->where('is_mature', false)->where(fn ($genres) => $genres->where('id', $family)->orWhere('parent_id', $family))->update(['is_mature' => true]);
    }

    /**
     * The earlier, over-broad flag is not restored: it hid legitimate genres.
     */
    public function down(): void {}
};
