<?php

return [
    'default' => env('CACHE_STORE', 'database'),
    'stores' => [
        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'cache',
            'lock_connection' => null,
            'lock_table' => 'cache_locks',
        ],
        'array' => [
            'driver' => 'array',
        ],
    ],
    'prefix' => env('CACHE_PREFIX', 'incarail_cache'),
];
