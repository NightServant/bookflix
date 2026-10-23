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

    'privacy' => [

        /*
        | Days a deactivated account is kept before it is permanently deleted.
        */

        'deactivated_retention_days' => (int) env('BOOKFLIX_DEACTIVATED_RETENTION_DAYS', 30),

    ],

];
