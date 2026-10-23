<?php

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

];
