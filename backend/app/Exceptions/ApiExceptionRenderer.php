<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiExceptionRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        $status = match (true) {
            $exception instanceof AuthenticationException => 401,
            $exception instanceof ValidationException => 422,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
        $code = match ($status) {
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            422 => 'VALIDATION_ERROR',
            429 => 'RATE_LIMIT',
            503 => 'SOURCE_UNAVAILABLE',
            default => 'SERVER_ERROR',
        };

        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $status >= 500 && $status !== 503 ? 'Error interno.' : $exception->getMessage(),
            'meta' => (object) [],
            'error' => [
                'code' => $code,
                'details' => $exception instanceof ValidationException ? $exception->errors() : null,
            ],
        ], $status, $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : []);
    }
}
