<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserProfileResource;
use App\Models\User;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();
        if (! $user || ! $user->is_active || ! Hash::check($request->validated('password'), $user->password)) {
            return Api::fail('INVALID_CREDENTIALS', 'Credenciales inválidas.', 401);
        }

        return Api::ok([
            'access_token' => $user->createToken('inca-rail-spa')->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 7200,
            'user' => (new UserProfileResource($user))->resolve($request),
        ], 'Autenticación correcta.');
    }

    public function me(Request $request): JsonResponse
    {
        return Api::ok((new UserProfileResource($request->user('api')))->resolve($request));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user('api')->token()?->revoke();

        return Api::ok(null, 'Sesión cerrada.');
    }
}
