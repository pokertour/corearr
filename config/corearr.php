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
];
