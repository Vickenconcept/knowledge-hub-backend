<?php

return [



    'paths' => ['api/*', 'sanctum/csrf-cookie', 'auth/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:4173',
        'http://127.0.0.1:4173',
        'https://knowledge-hub-frontend.vercel.app',
        'https://hub.phanrise.com'
    ],

    'allowed_origins_patterns' => ['/localhost:\d+/', '/\.vercel\.app$/'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];


// return [

//     'paths' => ['api/*', 'sanctum/csrf-cookie'],

//     'allowed_methods' => ['*'],

//     'allowed_origins' => [
//         'http://localhost:3000',
//         'http://127.0.0.1:3000',
//         'http://localhost:5173',
//         'http://127.0.0.1:5173',
//         'https://knowledge-hub-frontend.vercel.app',
//         'https://test.videngager.com', // your API domain
//     ],

//     'allowed_origins_patterns' => [],

//     'allowed_headers' => ['*'],

//     'exposed_headers' => [],

//     'max_age' => 0,

//     'supports_credentials' => true,

// ];
