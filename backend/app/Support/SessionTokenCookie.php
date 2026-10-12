<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class SessionTokenCookie
{
    public function make(string $token, Request $request): Cookie
    {
        return new Cookie(
            config('auth.session_cookie.name'),
            $token,
            0,
            '/api',
            null,
            config('session.secure') ?? $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    public function forget(Request $request): Cookie
    {
        return $this->make('', $request)->withExpires(1);
    }
}
