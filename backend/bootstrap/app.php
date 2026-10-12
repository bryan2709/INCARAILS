<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\ProtectCookieRequests;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\UsePassportCookie;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            UsePassportCookie::class,
            ProtectCookieRequests::class,
        ]);
        $middleware->appendToPriorityList(StartSession::class, UsePassportCookie::class);
        $middleware->appendToPriorityList(UsePassportCookie::class, ProtectCookieRequests::class);
        $middleware->alias(['active' => EnsureActiveUser::class, 'permission' => RequirePermission::class]);
        // An API opened directly in a browser also returns 401 JSON, without a login redirect.
        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(new ApiExceptionRenderer);
    })->create();
