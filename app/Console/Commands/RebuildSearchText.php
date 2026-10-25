<?php

namespace App\Console\Commands;

use App\Discovery\Search\SearchTextBuilder;
use App\Models\Media;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookflix:rebuild-search-text')]
#[Description('Rebuild the search text of every media')]
class RebuildSearchText extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SearchTextBuilder $builder): int
    {
        $rebuilt = 0;

        Media::query()->with(SearchTextBuilder::RELATIONS)->chunkById(200, function ($chunk) use ($builder, &$rebuilt): void {
            foreach ($chunk as $media) {
                $media->update(['search_text' => $builder->build($media)]);
                $rebuilt++;
            }
        });

        $this->info("Rebuilt the search text of {$rebuilt} media.");

        return self::SUCCESS;
    }
}
