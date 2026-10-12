<?php

namespace App\Services\Imports;

use App\Exceptions\InvalidSquadException;
use App\Models\Competition;
use App\Models\CompetitionSeason;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class SourcePayloadValidator
{
    public function season(array $data): array
    {
        if (Validator::make($data, [
            'id' => [
                'required',
                'integer',
                'min:1',
            ],
            'startDate' => [
                'required',
                'date_format:Y-m-d',
            ],
            'endDate' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:startDate',
            ],
            'currentMatchday' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ])->fails()) {
            throw new DomainException('SOURCE_SEASON_INVALID');
        }

        return $data;
    }

    public function teams(Competition $competition, CompetitionSeason $season, array $data): array
    {
        if ((int) ($data['competition']['id'] ?? -1) !== $competition->external_id) {
            throw new DomainException('SOURCE_COMPETITION_MISMATCH');
        }

        if ((int) ($data['season']['id'] ?? -1) !== $season->external_id) {
            throw new DomainException('SOURCE_SEASON_MISMATCH');
        }

        $teams = $data['teams'] ?? null;
        $expected = $data['count'] ?? null;
        if (! is_array($teams) || ! is_int($expected) || ! count($teams) || count($teams) !== $expected) {
            throw new DomainException('SOURCE_TEAMS_INCOMPLETE');
        }

        $quality = [
            'expected_teams' => $expected,
            'received_teams' => count($teams),
            'source_season_id' => $season->external_id,
            'source_fetched_at' => now()->toIso8601String(),
            ...$this->squadQuality($teams),
        ];
        if ($quality['invalid_squads_count']) {
            throw new InvalidSquadException($quality);
        }

        $teamIds = [];
        $playerIds = [];
        $missingPositions = 0;
        $missingShirts = 0;

        foreach ($teams as $team) {
            if (isset($teamIds[$team['id']])) {
                throw new DomainException('SOURCE_DUPLICATE_TEAM');
            }

            $teamIds[$team['id']] = true;
            $this->timestamp($team['lastUpdated'] ?? null);

            foreach ($team['squad'] ?? [] as $player) {
                if (! is_array($player)) {
                    throw new DomainException('SOURCE_PLAYER_INVALID');
                }

                if (isset($playerIds[$player['id']])) {
                    throw new DomainException('SOURCE_DUPLICATE_PLAYER');
                }

                $this->player($player);

                $playerIds[$player['id']] = true;
                $missingPositions += empty($player['position']) ? 1 : 0;
                $missingShirts += ! isset($player['shirtNumber']) ? 1 : 0;
            }
        }

        return [
            ...$quality,
            'players_count' => count($playerIds),
            'players_missing_position' => $missingPositions,
            'players_missing_shirt_number' => $missingShirts,
        ];
    }

    private function squadQuality(array $teams): array
    {
        $quality = [
            'players_count' => 0,
            'missing_squads_count' => 0,
            'empty_squads_count' => 0,
            'invalid_squads_count' => 0,
            'teams_with_incomplete_squads' => [],
        ];

        foreach ($teams as $team) {
            if (! is_array($team) || ! $this->identity($team)) {
                throw new DomainException('SOURCE_TEAM_INVALID');
            }

            $reason = match (true) {
                ! isset($team['squad']) => 'missing',
                ! is_array($team['squad']) || ! array_is_list($team['squad']) => 'invalid',
                $team['squad'] === [] => 'empty',
                default => null,
            };
            if ($reason !== null) {
                $quality[$reason.'_squads_count']++;
                $quality['teams_with_incomplete_squads'][] = [
                    'id' => (int) $team['id'],
                    'name' => $team['name'],
                    'reason' => $reason,
                ];
            } else {
                $quality['players_count'] += count($team['squad']);
            }
        }

        return $quality;
    }

    public function teamDetail(int $externalId, array $data): array
    {
        if ((int) ($data['id'] ?? -1) !== $externalId) {
            throw new DomainException('SOURCE_TEAM_MISMATCH');
        }
        $quality = $this->squadQuality([$data]);
        if ($quality['invalid_squads_count']) {
            throw new InvalidSquadException($quality);
        }
        $this->timestamp($data['lastUpdated'] ?? null);
        $ids = [];
        foreach ($data['squad'] ?? [] as $player) {
            if (! is_array($player)) {
                throw new DomainException('SOURCE_PLAYER_INVALID');
            }
            $this->player($player);
            if (isset($ids[$player['id']])) {
                throw new DomainException('SOURCE_DUPLICATE_PLAYER');
            }
            $ids[$player['id']] = true;
        }

        return [...$quality, 'source_fetched_at' => now()->toIso8601String()];
    }

    public function player(array $data): void
    {
        if (! $this->identity($data) || Validator::make($data, [
            'firstName' => ['nullable', 'string', 'max:255'],
            'lastName' => ['nullable', 'string', 'max:255'],
            'lastUpdated' => ['nullable', 'string', 'max:255'],
            'dateOfBirth' => ['nullable', 'date_format:Y-m-d'],
            'position' => ['nullable', 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:255'],
            'shirtNumber' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'currentTeam' => ['nullable', 'array'],
            'currentTeam.id' => ['required_with:currentTeam', 'integer', 'min:1'],
            'currentTeam.name' => ['required_with:currentTeam', 'string', 'max:255'],
        ])->fails()) {
            throw new DomainException('SOURCE_PLAYER_INVALID');
        }
        $this->timestamp($data['lastUpdated'] ?? null);
    }

    public function timestamp(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (trim($value) === '') {
            throw new DomainException('SOURCE_TIMESTAMP_INVALID');
        }

        try {
            return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new DomainException('SOURCE_TIMESTAMP_INVALID');
        }
    }

    private function identity(array $data): bool
    {
        return Validator::make($data, [
            'id' => [
                'required',
                'integer',
                'min:1',
            ],
            'name' => [
                'required',
                'string',
                'max:255',
            ],
        ])->passes();
    }
}
