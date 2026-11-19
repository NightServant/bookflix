<?php

namespace App\Console\Commands;

use App\Jobs\RefreshOutsideReviews;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('bookflix:refresh-outside-reviews {--limit=500 : How many of the most-read stories to refresh}')]
#[Description('Queue a refresh of outside reviews and ratings for the most-read stories')]
class RefreshOutsideReviewsCommand extends Command
{
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $ids = DB::table('user_library')
            ->join('media', 'media.id', '=', 'user_library.media_id')
            ->whereNull('media.deleted_at')->whereNull('media.merged_into_id')
            ->groupBy('media.id')
            ->orderByRaw('count(*) desc')->orderBy('media.id')
            ->limit($limit)->pluck('media.id');

        $ids->each(fn (int $id) => RefreshOutsideReviews::dispatch($id));

        $this->info("Queued outside reviews for {$ids->count()} stor".($ids->count() === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
