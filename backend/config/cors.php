<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'graphql', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Both hostnames point at the same loopback machine, but browsers treat
    // localhost and 127.0.0.1 as entirely different sites for CORS/cookie
    // purposes — allow whichever one the frontend actually gets opened with.
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
        'http://127.0.0.1:5173',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Content-Disposition carries the export filename — browsers hide response
    // headers from JS on a cross-origin request unless the server lists them here
    // (see downloadFile() in frontend/src/api/client.js).
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    // No longer needed for auth (the SPA uses a bearer token — see
    // config/lighthouse.php), but harmless to leave on.
    'supports_credentials' => true,

];
