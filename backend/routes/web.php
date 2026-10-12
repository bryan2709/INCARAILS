<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'app' => 'Inca Rail Football',
    'api' => '/api',
]));
