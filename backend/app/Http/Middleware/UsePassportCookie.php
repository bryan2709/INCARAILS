<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class UsePassportCookie
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->cookie(config('auth.session_cookie.name'));
        $fromCookie = ! $request->bearerToken() && is_string($token) && $token !== '';
        $request->attributes->set('passport_cookie', $fromCookie);
        if ($fromCookie) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        $response = $next($request);
        if ($fromCookie || $request->is('api/auth/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
