<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        if (! $request->user('api')?->hasPermission($permission)) {
            abort(403, 'Permiso requerido: '.$permission);
        }

        return $next($request);
    }
}
