<?php

namespace App\Services\Imports;

use App\Models\Area;
use App\Models\Competition;
use App\Models\CompetitionSeason;

class SourceCatalogMapper
{
    public function __construct(private SourcePayloadValidator $validator) {}

    public function area(?array $data): ?int
    {
        if (! $data || empty($data['id'])) {
            return null;
        }

        return Area::updateOrCreate([
            'external_id' => $data['id'],
        ], [
            'name' => $data['name'] ?? 'Sin nombre',
            'code' => $data['code'] ?? null,
            'flag_url' => $data['flag'] ?? null,
        ])->id;
    }

    public function season(Competition $competition, array $data): CompetitionSeason
    {
        $this->validator->season($data);

        return CompetitionSeason::updateOrCreate([
            'competition_id' => $competition->id,
            'external_id' => $data['id'],
        ], [
            'start_date' => $data['startDate'],
            'end_date' => $data['endDate'] ?? null,
            'current_matchday' => $data['currentMatchday'] ?? null,
        ]);
    }
}
