<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CompetitionResource extends JsonResource
{
    public function toArray($request): array
    {
        $active = $this->activeSnapshot;
        $season = $this->currentSeason;

        return [
            'id' => $this->external_id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'emblem' => $this->emblem_url,
            'plan' => $this->source_plan,
            'source_updated_at' => $this->source_updated_at?->toIso8601String(),
            'last_updated_at' => $active?->published_at?->toIso8601String(),
            'season' => $season ? [
                'id' => $season->external_id,
                'start_date' => $season->start_date,
                'end_date' => $season->end_date,
            ] : null,
            'published_season_id' => $active?->season?->external_id,
            'snapshot_id' => $active?->id,
            'import_status' => $this->import_status ?? ($active ? 'published' : 'not_imported'),
            'needs_refresh' => $active && $season && $active->season_id !== $season->id,
        ];
    }
}
