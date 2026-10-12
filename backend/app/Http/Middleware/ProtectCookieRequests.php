<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

class ProtectCookieRequests extends PreventRequestForgery
{
    public function handle($request, Closure $next)
    {
        if (! $request->attributes->get('passport_cookie') && ! $request->is('api/auth/login', 'api/auth/csrf-cookie')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    protected function hasValidOrigin($request)
    {
        return false;
    }

    protected function newCookie($request, $config)
    {
        return parent::newCookie($request, $config)->withExpires(0);
    }
}
