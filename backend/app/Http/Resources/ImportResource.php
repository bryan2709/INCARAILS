<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ImportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'quality_summary' => $this->quality_summary,
            'url' => '/api/imports/'.$this->id,
            'steps' => $this->whenLoaded('steps', fn () => $this->steps->map->only(['step_key', 'status', 'error_code'])),
        ];
    }
}
