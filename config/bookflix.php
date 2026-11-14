<?php

use App\Catalog\Providers\AniList\AniListProvider;
use App\Catalog\Providers\ComicVine\ComicVineProvider;
use App\Catalog\Providers\Hardcover\HardcoverProvider;
use App\Catalog\Providers\MangaDex\MangaDexProvider;
use App\Catalog\Providers\MangaUpdates\MangaUpdatesProvider;
use App\Catalog\Providers\Metron\MetronProvider;
use App\Catalog\Providers\OpenLibrary\OpenLibraryProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Supported Languages
    |--------------------------------------------------------------------------
    |
    | ISO 639-1 codes a reader can pick as a preferred language.
    |
    */

    'languages' => ['en', 'ja', 'ko', 'zh', 'fr', 'de', 'es', 'it', 'pt', 'ru'],

    /*
    |--------------------------------------------------------------------------
    | Avatar Upload Limit
    |--------------------------------------------------------------------------
    |
    | Maximum avatar size in kilobytes.
    |
    */

    'avatar_max_kb' => 2048,

    /*
    |--------------------------------------------------------------------------
    | Avatar Disk
    |--------------------------------------------------------------------------
    |
    | Disk holding uploaded avatars. Must be publicly readable; kept separate
    | from the default disk so Livewire temporary uploads stay private.
    |
    */

    'avatar_disk' => env('BOOKFLIX_AVATAR_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Primary Navigation
    |--------------------------------------------------------------------------
    |
    | Rendered by the desktop header and the mobile bottom nav. `active` lists
    | the route name patterns that mark the item as current; `desktop => false`
    | leaves it out of the desktop header (the palette trigger covers it there).
    |
    */

    'navigation' => [
        ['label' => 'Home', 'icon' => 'home', 'route' => 'home', 'active' => ['home']],
        ['label' => 'Discover', 'icon' => 'globe-alt', 'route' => 'discover', 'active' => ['discover', 'explore.*']],
        ['label' => 'Search', 'icon' => 'magnifying-glass', 'route' => 'search', 'active' => ['search'], 'desktop' => false],
        ['label' => 'Library', 'icon' => 'book-open', 'route' => 'library.index', 'active' => ['library.*', 'collections.*', 'history.*']],
        ['label' => 'Profile', 'icon' => 'user', 'route' => 'profile.edit', 'active' => ['profile.edit', 'security.edit', 'privacy.edit', 'preferences.edit']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Navigation
    |--------------------------------------------------------------------------
    |
    | Rendered by the admin sidebar (layouts/admin). Groups in order; an item's
    | `active` lists the route name patterns that mark it as current, and an
    | optional `count` names the figure in App\Admin\AdminCounts shown beside it.
    |
    */

    'admin_navigation' => [
        ['group' => null, 'items' => [
            ['label' => 'Overview', 'icon' => 'squares-2x2', 'route' => 'admin.index', 'active' => ['admin.index']],
        ]],
        ['group' => 'Catalog', 'items' => [
            ['label' => 'Sync', 'icon' => 'arrow-path', 'route' => 'admin.providers', 'active' => ['admin.providers']],
            ['label' => 'Media', 'icon' => 'book-open', 'route' => 'admin.media.index', 'active' => ['admin.media.*']],
            ['label' => 'Duplicates', 'icon' => 'square-2-stack', 'route' => 'admin.duplicates', 'active' => ['admin.duplicates'], 'count' => 'duplicates'],
            ['label' => 'Classification', 'icon' => 'rectangle-group', 'route' => 'admin.classifications', 'active' => ['admin.classifications'], 'count' => 'classifications'],
            ['label' => 'Staff picks', 'icon' => 'star', 'route' => 'admin.staff-picks', 'active' => ['admin.staff-picks']],
        ]],
        ['group' => 'Intelligence', 'items' => [
            ['label' => 'Models', 'icon' => 'cpu-chip', 'route' => 'admin.models', 'active' => ['admin.models']],
        ]],
        ['group' => 'People', 'items' => [
            ['label' => 'Users', 'icon' => 'users', 'route' => 'admin.users', 'active' => ['admin.users']],
        ]],
    ],

    /*
    |--------------------------------------------------------------------------
    | Collections
    |--------------------------------------------------------------------------
    |
    | The most stories one collection may hold. A collection page lists them all
    | in one reorderable list, so the page stays bounded. `max_per_reader` is how
    | many collections one reader may keep, so the lists of them stay bounded too.
    |
    */

    'collections' => [
        'max_items' => 300,
        'max_per_reader' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Library Writes
    |--------------------------------------------------------------------------
    |
    | How many changes one reader may make to their library in a minute through
    | the pages (status, favorite, progress, rating, collections and the stories
    | in them), counted together for all of them. Looking is not limited.
    |
    */

    'library' => [
        'writes_per_minute' => 60,
    ],

    'privacy' => [

        /*
        | Days a deactivated account is kept before it is permanently deleted.
        */

        'deactivated_retention_days' => (int) env('BOOKFLIX_DEACTIVATED_RETENTION_DAYS', 30),

    ],

    /*
    |--------------------------------------------------------------------------
    | Data Export
    |--------------------------------------------------------------------------
    |
    | "Export my data" (Settings, Privacy) writes one zip per reader to `disk` and
    | emails a signed link that works for `link_hours`. The disk holds a reader's
    | whole history: it must be private, and a public disk is refused. A file is
    | deleted after `retention_days`, and a reader may ask again after
    | `cooldown_minutes`.
    |
    */

    'exports' => [
        'disk' => env('BOOKFLIX_EXPORT_DISK', 'local'),
        'link_hours' => 24,
        'retention_days' => 7,
        'cooldown_minutes' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata Providers
    |--------------------------------------------------------------------------
    |
    | Providers stay disabled until their terms are reviewed. `enabled` here is
    | only the value the seeder gives a new source; afterwards the
    | `external_sources.enabled` column is the one source of truth. The user
    | agent identifies the project, never a person, on every provider request.
    |
    | `rate_per_minute` counts page jobs, not HTTP requests: an AniList page is
    | one request, an Open Library page is ten works at several requests each.
    | `max_pages_per_run` bounds one sync; the next run resumes where it stopped.
    | `allowed_hosts` are the only hosts whose https cover and source URLs are kept.
    | A provider whose `class` is null is not built yet: its source is seeded but
    | never synced, listed, or allowed for covers.
    |
    */

    'provider_user_agent' => env('BOOKFLIX_PROVIDER_USER_AGENT', 'Bookflix/0.1 (+https://github.com/NightServant/bookflix)'),

    'providers' => [

        'openlibrary' => [
            'class' => OpenLibraryProvider::class,
            'enabled' => (bool) env('BOOKFLIX_OPENLIBRARY_ENABLED', false),
            'rate_per_minute' => 2,
            'max_pages_per_run' => 20,
            'allowed_hosts' => ['openlibrary.org', 'covers.openlibrary.org'],
        ],

        'anilist' => [
            'class' => AniListProvider::class,
            'enabled' => (bool) env('BOOKFLIX_ANILIST_ENABLED', false),
            'rate_per_minute' => 30,
            'max_pages_per_run' => 20,
            'allowed_hosts' => ['anilist.co', 's4.anilist.co'],
        ],

        'mangadex' => [
            'class' => MangaDexProvider::class,
            'enabled' => (bool) env('BOOKFLIX_MANGADEX_ENABLED', false),
            'rate_per_minute' => 30,
            'max_pages_per_run' => 20,
            'allowed_hosts' => ['mangadex.org', 'api.mangadex.org', 'uploads.mangadex.org'],
        ],

        'mangaupdates' => [
            'class' => MangaUpdatesProvider::class,
            'enabled' => (bool) env('BOOKFLIX_MANGAUPDATES_ENABLED', false),
            'rate_per_minute' => 20,
            'max_pages_per_run' => 20,
            'allowed_hosts' => ['www.mangaupdates.com', 'api.mangaupdates.com', 'cdn.mangaupdates.com'],
        ],

        'comicvine' => [
            'class' => ComicVineProvider::class,
            'api_key' => env('BOOKFLIX_COMICVINE_API_KEY'),
            'enabled' => (bool) env('BOOKFLIX_COMICVINE_ENABLED', false),
            'rate_per_minute' => 3,
            'max_pages_per_run' => 20,
            'allowed_hosts' => ['comicvine.gamespot.com'],
        ],

        'metron' => [
            'class' => MetronProvider::class,
            'username' => env('BOOKFLIX_METRON_USERNAME'),
            'password' => env('BOOKFLIX_METRON_PASSWORD'),
            'enabled' => (bool) env('BOOKFLIX_METRON_ENABLED', false),
            // One page job a minute; each makes at most 19 requests (see MetronProvider::DETAILS_PER_PAGE), under Metron's polite 20 a minute.
            'rate_per_minute' => 1,
            'max_pages_per_run' => 20,
            'allowed_hosts' => ['metron.cloud', 'static.metron.cloud'],
        ],

        'hardcover' => [
            'class' => HardcoverProvider::class,
            'api_token' => env('BOOKFLIX_HARDCOVER_API_TOKEN'),
            'enabled' => (bool) env('BOOKFLIX_HARDCOVER_ENABLED', false),
            'rate_per_minute' => 30,
            'max_pages_per_run' => 20,
            'allowed_hosts' => ['hardcover.app', 'api.hardcover.app', 'assets.hardcover.app'],
        ],

        'wikidata' => [
            'class' => null,
            'enabled' => (bool) env('BOOKFLIX_WIKIDATA_ENABLED', false),
            'rate_per_minute' => 20,
            'max_pages_per_run' => 20,
            'allowed_hosts' => ['www.wikidata.org', 'query.wikidata.org'],
        ],

        'wikipedia' => [
            'class' => null,
            'enabled' => (bool) env('BOOKFLIX_WIKIPEDIA_ENABLED', false),
            'rate_per_minute' => 30,
            'max_pages_per_run' => 20,
            'allowed_hosts' => ['wikimedia.org', 'en.wikipedia.org'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Signal Sources
    |--------------------------------------------------------------------------
    |
    | `bookflix:sync-signals` checks this many media per run, least recently checked first:
    | Wikidata lookups share one SPARQL query per run, each Wikipedia pageview check is one request.
    |
    */

    'signals' => [
        'wikidata_batch' => 50,
        'wikipedia_batch' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Cursor
    |--------------------------------------------------------------------------
    |
    | A finished sync moves a provider's cursor to the moment its walk began,
    | minus this many minutes, so a record updated while the walk was running is
    | read again by the next one. Imports are idempotent, so the overlap is harmless.
    |
    */

    'sync' => [
        'cursor_overlap_minutes' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Popularity
    |--------------------------------------------------------------------------
    |
    | `bookflix:score-popularity` ranks every (source, metric) pair as a percentile
    | and averages a media's ranks with these weights. A metric not listed weighs 1.
    | The readers' own rating aggregate (the synthetic `bookflix` source) is left
    | out until Phase 6 gives it a place in the recommender.
    |
    */

    'popularity' => [
        'weights' => [
            'popularity' => 3,
            'follows' => 3,
            'pageviews_30d' => 3,
            'favourites' => 2,
            'ratings_count' => 1,
            'average_rating' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Duplicate Detection
    |--------------------------------------------------------------------------
    |
    | Title similarity (pg_trgm) at or above `auto_title_similarity` merges
    | automatically when a creator is shared and the years are within
    | `year_window`. From `review_min_similarity` up, an admin reviews the pair;
    | for review a title also matches the other side's alternative titles.
    |
    */

    'dedup' => [
        'auto_title_similarity' => 0.90,
        'review_min_similarity' => 0.75,
        'year_window' => 1,
        // Taken off a pair's score when both sides credit someone and no one is on both, never below `review_min_similarity`: the pair
        // sinks down the review queue and never auto-merges, and an admin still decides (one person is often spelled two ways).
        'creator_mismatch_penalty' => 0.2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Length Buckets
    |--------------------------------------------------------------------------
    |
    | The length filter: up to `short` is short, up to `medium` is medium, more is
    | long. A media with a page count is bucketed by pages, otherwise by chapters.
    |
    */

    'length_buckets' => [
        'pages' => ['short' => 200, 'medium' => 500],
        'chapters' => ['short' => 30, 'medium' => 100],
    ],

    /*
    |--------------------------------------------------------------------------
    | Classification
    |--------------------------------------------------------------------------
    |
    | Automatic classes below `min_confidence` are dropped. The public site shows
    | accepted classes plus pending ones at or above `public_min_confidence`.
    | A media keeps one primary class and at most `max_secondary` secondary ones.
    | The Dewey classifier's confidence is capped at `classifier_max_confidence`,
    | below the public minimum, so its guesses wait for a reviewer.
    |
    */

    'classification' => [
        'public_min_confidence' => 0.80,
        'min_confidence' => 0.50,
        'max_secondary' => 3,
        'classifier_max_confidence' => 0.79,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | The semantic leg keeps a neighbour only when its cosine similarity to the
    | query is at least `semantic_min_similarity`; to be tuned on the real model.
    | Its timeout, cache and pause after a failure are SemanticLeg's constants.
    |
    */

    'search' => [
        'semantic_min_similarity' => 0.80,
    ],

    /*
    |--------------------------------------------------------------------------
    | Interaction Weights
    |--------------------------------------------------------------------------
    |
    | How much each signal says about taste, seeded into the `interaction-weights`
    | model version (D-15). `reading_progress` is per percentage point of the
    | event's value; a rating counts (score - `rating_midpoint`) * `rating_scale`;
    | a signal loses half its weight every `half_life_days`.
    |
    */

    'interaction_weights' => [
        'view' => 1,
        'click' => 1,
        'recommendation_click' => 1.5,
        'library_add' => 3,
        'reading_start' => 4,
        'reading_progress' => 0.05,
        'reading_complete' => 8,
        'favorite' => 6,
        'rating_midpoint' => 5.5,
        'rating_scale' => 1.2,
        'review' => 4,
        'abandon' => -4,
        'search' => 0,
        'half_life_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hybrid Weights
    |--------------------------------------------------------------------------
    |
    | How the ranker blends its normalized features, seeded into the
    | `hybrid-weights` model version. The weights besides `context` add up to 1.
    |
    */

    'hybrid_weights' => [
        'content' => 0.30,
        'collaborative' => 0.25,
        'dewey' => 0.10,
        'popularity' => 0.10,
        'freshness' => 0.05,
        'preference' => 0.15,
        'novelty' => 0.05,
        'context' => 0.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recommendations
    |--------------------------------------------------------------------------
    |
    | `cache_minutes` is how long a reader's ranked shelves are kept,
    | `cold_start_min_interactions` how many signals a reader needs before the
    | personalized shelves replace the cold-start ones, and `epsilon` the share
    | of a shelf given to exploration. `trending_weight` is how much of
    | Bookflix's own activity (0 to 1) `Media::trending()` adds to the
    | provider popularity score (0 to 1).
    |
    */

    'recommendations' => [
        'cache_minutes' => 10,
        'cold_start_min_interactions' => 5,
        'epsilon' => 0.10,
        // Order Similar To, Popular in Your Genres and Discover Something New by Thompson sampling of their click rates once each has enough impressions.
        'bandit_enabled' => false,
        // A shelf of candidates that the shelves before it left with fewer titles than this is not shown.
        'min_shelf_size' => 4,
        'trending_weight' => 0.05,
        // How long the log of served recommendations (and its events) is kept before `model:prune` deletes it.
        'retention_days' => 90,
        // A library add counts as led to by a tile when its click was this recent.
        'library_add_window_minutes' => 30,
        // How long Home's undo toast stays; browser tests shorten it so they do not wait real seconds.
        'undo_seconds' => 10,
    ],

];
