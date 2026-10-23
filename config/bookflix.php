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
    | Providers stay disabled until their terms are reviewed. The user agent
    | identifies the project, never a person, on every provider request.
    |
    */

    'provider_user_agent' => env('BOOKFLIX_PROVIDER_USER_AGENT', 'Bookflix/0.1 (+https://github.com/NightServant/bookflix)'),

    'providers' => [

        'openlibrary' => [
            'class' => OpenLibraryProvider::class,
            'enabled' => (bool) env('BOOKFLIX_OPENLIBRARY_ENABLED', false),
            'rate_per_minute' => 100,
        ],

        'anilist' => [
            'class' => AniListProvider::class,
            'enabled' => (bool) env('BOOKFLIX_ANILIST_ENABLED', false),
            'rate_per_minute' => 30,
        ],

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

];
