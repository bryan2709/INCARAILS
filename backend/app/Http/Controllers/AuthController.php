<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserProfileResource;
use App\Models\User;
use App\Support\Api;
use App\Support\SessionTokenCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController
{
    public function csrf(): JsonResponse
    {
        return Api::ok(null, 'Protección CSRF preparada.');
    }

    public function login(LoginRequest $request, SessionTokenCookie $cookies): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();
        if (! $user || ! $user->is_active || ! Hash::check($request->validated('password'), $user->password)) {
            return Api::fail('INVALID_CREDENTIALS', 'Credenciales inválidas.', 401);
        }

        $request->user('api')?->token()?->revoke();
        $request->session()->regenerate(true);
        $token = $user->createToken('inca-rail-spa');

        return Api::ok([
            'expires_in' => 7200,
            'user' => (new UserProfileResource($user))->resolve($request),
        ], 'Autenticación correcta.')->withCookie($cookies->make($token->accessToken, $request));
    }

    public function me(Request $request): JsonResponse
    {
        return Api::ok((new UserProfileResource($request->user('api')))->resolve($request));
    }

    public function logout(Request $request, SessionTokenCookie $cookies): JsonResponse
    {
        $request->user('api')->token()?->revoke();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Api::ok(null, 'Sesión cerrada.')->withCookie($cookies->forget($request));
    }
}
