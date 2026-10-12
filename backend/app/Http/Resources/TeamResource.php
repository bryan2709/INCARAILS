<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'name' => $this->name,
            'short_name' => $this->short_name,
            'tla' => $this->tla,
            'crest' => $this->crest_url,
            'area' => $this->whenLoaded('area', fn () => $this->area ? [
                'id' => $this->area->external_id,
                'name' => $this->area->name,
                'code' => $this->area->code,
                'flag' => $this->area->flag_url,
            ] : null),
            'source_updated_at' => $this->source_updated_at?->toIso8601String(),
            ...($this->source_profile ?? []),
            'id' => $this->external_id,
            'first_published_at' => $this->first_published_at,
            'last_updated_at' => $this->source_fetched_at?->toIso8601String(),
        ];
    }
}
