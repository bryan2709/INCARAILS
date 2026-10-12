<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class FootballClient
{
    public function get(string $resource, string $token): Response
    {
        $path = $this->path($resource);

        return Http::withHeaders([
            'X-Auth-Token' => $token,
            'Accept' => 'application/json',
        ])
            ->connectTimeout(7)->timeout(config('football.timeout', 20))
            ->get(rtrim(config('football.base_url'), '/').$path);
    }

    public function errorMessage(int $status, ?array $body): string
    {
        $summary = 'Solicitud externa fallida (HTTP '.($status ?: 'conexión').').';
        $message = $body['message'] ?? null;

        return is_string($message) && trim($message) !== ''
            ? $summary.' '.trim($message)
            : $summary;
    }

    public function retryAfter(Response $response): int
    {
        $value = $response->header('Retry-After');
        if (! $value) {
            return 0;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        try {
            return max(0, (int) ceil(now()->diffInSeconds(Carbon::parse($value))));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function path(string $resource): string
    {
        if ($resource === 'catalog') {
            return '/competitions';
        }

        if (preg_match('/^competition_detail:([1-9][0-9]*)$/', $resource, $parts)) {
            return '/competitions/'.$parts[1];
        }

        if (preg_match('/^competition_teams:([1-9][0-9]*):([0-9]{4})$/', $resource, $parts)) {
            return '/competitions/'.$parts[1].'/teams?season='.$parts[2];
        }

        if (preg_match('/^team_detail:([1-9][0-9]*)$/', $resource, $parts)) {
            return '/teams/'.$parts[1];
        }

        if (preg_match('/^player_detail:([1-9][0-9]*)$/', $resource, $parts)) {
            return '/persons/'.$parts[1];
        }

        throw new InvalidArgumentException('Recurso externo inválido.');
    }
}
