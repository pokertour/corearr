<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CoreArr Versioning
    |--------------------------------------------------------------------------
    |
    | This value determines the current version of the application.
    | It is provided as an environment variable (not from .env)
    | or defaults to 'local-dev' if not present.
    |
    */
    'version' => env('APP_VERSION', 'local-dev'),

    /*
    |--------------------------------------------------------------------------
    | qBittorrent speed graph (scheduled sampling)
    |--------------------------------------------------------------------------
    |
    | How often the scheduler records DL/UL speeds (minutes), and how long rows
    | are kept before deletion. Requires `php artisan schedule:run` (or Laravel
    | Cloud scheduler) plus a configured queue/runner when using async queues.
    |
    */
    'qbittorrent_speed_sample_interval_minutes' => max(1, min(60, (int) env('QBITTORRENT_SPEED_SAMPLE_INTERVAL_MINUTES', 5))),

    'qbittorrent_speed_sample_retention_hours' => max(1, min(168, (int) env('QBITTORRENT_SPEED_RETENTION_HOURS', 24))),
];
