<?php

use App\Catalog\Providers\AniList\AniListProvider;
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
    | the route name patterns that mark the item as current.
    |
    */

    'navigation' => [
        ['label' => 'Home', 'icon' => 'home', 'route' => 'home', 'active' => ['home']],
        ['label' => 'Discover', 'icon' => 'globe-alt', 'route' => 'explore.index', 'active' => ['explore.*']],
        ['label' => 'Profile', 'icon' => 'user', 'route' => 'profile.edit', 'active' => ['profile.edit', 'security.edit', 'privacy.edit']],
    ],

    'privacy' => [

        /*
        | Days a deactivated account is kept before it is permanently deleted.
        */

        'deactivated_retention_days' => (int) env('BOOKFLIX_DEACTIVATED_RETENTION_DAYS', 30),

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
    | Duplicate Detection
    |--------------------------------------------------------------------------
    |
    | Title similarity (pg_trgm) at or above `auto_title_similarity` merges
    | automatically when a creator is shared and the years are within
    | `year_window`. From `review_min_similarity` up, an admin reviews the pair.
    |
    */

    'dedup' => [
        'auto_title_similarity' => 0.90,
        'review_min_similarity' => 0.75,
        'year_window' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Classification
    |--------------------------------------------------------------------------
    |
    | Automatic classes below `min_confidence` are dropped. The public site shows
    | accepted classes plus pending ones at or above `public_min_confidence`.
    | A media keeps one primary class and at most `max_secondary` secondary ones.
    |
    */

    'classification' => [
        'public_min_confidence' => 0.80,
        'min_confidence' => 0.50,
        'max_secondary' => 3,
    ],

];
