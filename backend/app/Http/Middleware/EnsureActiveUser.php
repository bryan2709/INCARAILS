<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user('api')?->is_active) {
            abort(403, 'Usuario desactivado.');
        }

        return $next($request);
    }
}
