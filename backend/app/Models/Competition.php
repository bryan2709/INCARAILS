<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Competition extends Model
{
    protected $table = 'competitions';

    public $timestamps = true;

    protected $fillable = [
        'external_id',
        'area_id',
        'name',
        'code',
        'type',
        'emblem_url',
        'source_plan',
        'current_season_id',
        'active_snapshot_id',
        'source_updated_at',
        'catalog_fetched_at',
    ];

    public function activeSnapshot()
    {
        return $this->belongsTo(CompetitionSnapshot::class, 'active_snapshot_id');
    }

    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'current_season_id' => 'integer',
            'active_snapshot_id' => 'integer',
            'source_updated_at' => 'datetime',
            'catalog_fetched_at' => 'datetime',
        ];
    }

    public function currentSeason()
    {
        return $this->belongsTo(CompetitionSeason::class, 'current_season_id');
    }
}
