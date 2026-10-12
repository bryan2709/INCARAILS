<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FootballController;
use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

foreach ([
    'id',
    'competition_id',
    'team_id',
    'player_id',
    'import_id',
] as $parameter) {
    Route::pattern($parameter, '[1-9][0-9]*');
}

Route::post('/auth/login', [
    AuthController::class,
    'login',
])->middleware('throttle:10,1');
Route::get('/auth/csrf-cookie', [AuthController::class, 'csrf'])->middleware('throttle:120,1');
Route::middleware([
    'auth:api',
    'active',
    'throttle:120,1',
])->group(function () {
    Route::post('/auth/logout', [
        AuthController::class,
        'logout',
    ])->withoutMiddleware('active');
    Route::get('/auth/me', [
        AuthController::class,
        'me',
    ]);
    Route::middleware('permission:football.view')->group(function () {
        Route::get('/competitions', [
            FootballController::class,
            'competitions',
        ]);
        Route::get('/competitions/{competition_id}', [
            FootballController::class,
            'competition',
        ]);
        Route::get('/team', [
            FootballController::class,
            'teams',
        ]);
        Route::get('/team/{team_id}', [
            FootballController::class,
            'team',
        ]);
        Route::get('/players', [
            FootballController::class,
            'players',
        ]);
        Route::get('/players/{player_id}', [
            FootballController::class,
            'player',
        ]);
        Route::get('/imports/{import_id}', [
            ImportController::class,
            'show',
        ]);
    });
    Route::middleware('permission:football.manage')->group(function () {
        Route::post('/team/{team_id}/refresh', [ImportController::class, 'refreshTeam']);
        Route::post('/players/{player_id}/refresh', [ImportController::class, 'refreshPlayer']);
        Route::post('/competitions/{competition_id}/refresh', [
            ImportController::class,
            'refresh',
        ]);
        Route::post('/imports/{import_id}/retry', [
            ImportController::class,
            'retry',
        ]);
    });
    Route::middleware('permission:administration.manage')->group(function () {
        Route::get('/users', [
            AdminController::class,
            'users',
        ]);
        Route::get('/users/{id}', [
            AdminController::class,
            'user',
        ]);
        Route::post('/users', [
            AdminController::class,
            'createUser',
        ]);
        Route::patch('/users/{id}', [
            AdminController::class,
            'updateUser',
        ]);
        Route::patch('/users/{id}/status', [
            AdminController::class,
            'statusUser',
        ]);
        Route::put('/users/{id}/roles', [
            AdminController::class,
            'userRoles',
        ]);
        Route::get('/roles', [
            AdminController::class,
            'roles',
        ]);
        Route::get('/roles/{id}', [
            AdminController::class,
            'role',
        ]);
        Route::post('/roles', [
            AdminController::class,
            'createRole',
        ]);
        Route::patch('/roles/{id}', [
            AdminController::class,
            'updateRole',
        ]);
        Route::put('/roles/{id}/permissions', [
            AdminController::class,
            'rolePermissions',
        ]);
        Route::get('/permissions', [
            AdminController::class,
            'permissions',
        ]);
        Route::get('/permissions/{id}', [
            AdminController::class,
            'permission',
        ]);
        Route::post('/permissions', [
            AdminController::class,
            'createPermission',
        ]);
        Route::patch('/permissions/{id}', [
            AdminController::class,
            'updatePermission',
        ]);
    });
});
