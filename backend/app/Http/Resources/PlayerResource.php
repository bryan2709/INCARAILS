<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PlayerResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'nationality' => $this->nationality,
            'date_of_birth' => $this->date_of_birth,
            'source_updated_at' => $this->source_updated_at?->toIso8601String(),
            ...($this->source_profile ?? []),
            'id' => $this->external_id,
            'first_published_at' => $this->first_published_at,
            'last_updated_at' => $this->source_fetched_at?->toIso8601String(),
        ];
    }
}
