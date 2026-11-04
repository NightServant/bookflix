<?php

namespace App\Providers;

use App\Discovery\Recommendations\CandidatePool;
use App\Discovery\Recommendations\SeedItems;
use App\Discovery\Recommendations\Sources\CollaborativeSource;
use App\Discovery\Recommendations\Sources\ContentSimilarSource;
use App\Discovery\Recommendations\Sources\DeweySimilarSource;
use App\Discovery\Recommendations\Sources\EditorialSource;
use App\Discovery\Recommendations\Sources\ExplorationSource;
use App\Discovery\Recommendations\Sources\NewReleasesSource;
use App\Discovery\Recommendations\Sources\PopularInGenresSource;
use App\Discovery\Recommendations\Sources\TrendingSource;
use Illuminate\Support\ServiceProvider;

class RecommendationServiceProvider extends ServiceProvider
{
    /** The tag the candidate pool (6.8) resolves every source by. */
    public const SOURCES = 'recommendation.sources';

    /**
     * Register services.
     */
    public function register(): void
    {
        // One instance, so a build's memo is shared by the sources, the ranker and the shelf builder; it holds nothing outside a build.
        $this->app->singleton(SeedItems::class);

        $this->app->tag([
            ContentSimilarSource::class,
            CollaborativeSource::class,
            DeweySimilarSource::class,
            TrendingSource::class,
            PopularInGenresSource::class,
            NewReleasesSource::class,
            EditorialSource::class,
            ExplorationSource::class,
        ], self::SOURCES);

        $this->app->when(CandidatePool::class)->needs('$sources')->giveTagged(self::SOURCES);
    }
}
