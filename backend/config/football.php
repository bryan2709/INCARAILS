<?php

return [
    'base_url' => env('FOOTBALL_BASE_URL', 'https://api.football-data.org/v4'),
    'worker_key' => env('EXTERNAL_WORKER_KEY', 'worker-a'),
    'global_limit' => (int) env('FOOTBALL_GLOBAL_LIMIT', 10),
    'window' => (int) env('FOOTBALL_WINDOW_SECONDS', 60),
    'timeout' => (int) env('FOOTBALL_HTTP_TIMEOUT', 20),
    'snapshot_retention_hours' => (int) env('FOOTBALL_SNAPSHOT_RETENTION_HOURS', 24),
    'credentials' => [
        'FOOTBALL_TOKEN_A' => env('FOOTBALL_TOKEN_A'),
        'FOOTBALL_TOKEN_B' => env('FOOTBALL_TOKEN_B'),
        'FOOTBALL_TOKEN_C' => env('FOOTBALL_TOKEN_C'),
    ],
];
