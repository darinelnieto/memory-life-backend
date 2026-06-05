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

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:9000',
        'http://localhost:9002',
        'http://localhost:9003',
        'http://localhost:3000',
        'http://localhost:8080',
        'http://127.0.0.1:9000',
        'http://127.0.0.1:9002',
        'http://127.0.0.1:9003',
        'https://tuwebdeboda.com',
        'https://www.tuwebdeboda.com',
        'https://memory-life-frontend-git-main-darinelnietos-projects.vercel.app',
        'https://memory-life-frontend-97zheq13m-darinelnietos-projects.vercel.app',
    ],

    'allowed_origins_patterns' => [
        '#^https://.*\.vercel\.app$#',
        '#^http://localhost:\d+$#',
        '#^http://127\.0\.0\.1:\d+$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
