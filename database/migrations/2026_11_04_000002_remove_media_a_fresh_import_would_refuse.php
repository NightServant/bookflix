<?php

use App\Catalog\Ingestion\ProhibitedContent;
use App\Models\Media;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Works imported before the hard rule (see ProhibitedContent) can still carry a term it names: Hardcover's "Pedophilia" content warning on
     * 22 books in one catalog. A fresh import refuses those records, and a re-sync can never update them, so they are soft-deleted here and the
     * catalog matches what a fresh import holds. The stored terms checked are the ones the importers keep: the provider's warning list
     * (`media.content_warnings`) and the tags. Deleting through the model drops the cached class counts, as any removal does.
     */
    public function up(): void
    {
        $prohibitedTags = DB::table('tags')->get(['id', 'name'])->filter(fn (object $tag): bool => ProhibitedContent::matches((string) $tag->name))->pluck('id');
        $tagged = DB::table('media_tags')->whereIn('tag_id', $prohibitedTags)->pluck('media_id');

        $warned = DB::table('media')->whereNull('deleted_at')->where('content_warnings', '!=', '[]')->select(['id', 'content_warnings'])->lazyById(1000)
            ->filter(function (object $row): bool {
                $terms = json_decode((string) $row->content_warnings, true);

                return is_array($terms) && array_filter($terms, fn (mixed $term): bool => is_string($term) && ProhibitedContent::matches($term)) !== [];
            })
            ->pluck('id');

        Media::query()->whereIn('id', $tagged->merge($warned)->unique()->values())->get()->each->delete();
    }

    /**
     * The rows are soft-deleted, so an admin can restore one by hand; restoring them all would undo the rule.
     */
    public function down(): void {}
};
