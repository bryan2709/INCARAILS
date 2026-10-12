<?php

namespace App\Support;

class Api
{
    public static function ok(mixed $data = null, string $message = 'OK', array $meta = [], int $status = 200)
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => (object) $meta,
            'error' => null,
        ], $status);
    }

    public static function fail(string $code, string $message, int $status = 422, array $details = [])
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => $message,
            'meta' => (object) [],
            'error' => [
                'code' => $code,
                'details' => $details,
            ],
        ], $status);
    }
}
