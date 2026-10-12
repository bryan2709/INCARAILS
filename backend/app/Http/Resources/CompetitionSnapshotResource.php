<?php

namespace App\Http\Resources;

use App\Models\SnapshotPlayer;
use App\Models\SnapshotTeam;
use Illuminate\Http\Resources\Json\JsonResource;

class CompetitionSnapshotResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'published_at' => $this->published_at?->toIso8601String(),
            'quality_summary' => $this->quality_summary,
            'season' => [
                'id' => $this->season->external_id,
                'start_date' => $this->season->start_date,
                'end_date' => $this->season->end_date,
            ],
            'teams_count' => $this->teams->count(),
            'players_count' => $this->teams->sum(fn (SnapshotTeam $team) => $team->players->count()),
            'teams' => $this->teams->map(fn (SnapshotTeam $team) => [
                'id' => $team->team->external_id,
                'name' => $team->name,
                'short_name' => $team->short_name,
                'tla' => $team->metadata['tla'] ?? null,
                'crest' => $team->crest_url,
                'players' => $team->players->map(fn (SnapshotPlayer $player) => [
                    'id' => $player->player->external_id,
                    'name' => $player->name,
                    'position' => $player->position,
                    'shirt_number' => $player->shirt_number,
                ])->all(),
            ])->all(),
        ];
    }
}
