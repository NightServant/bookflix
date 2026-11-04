<?php

namespace App\Providers;

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
    }
}
