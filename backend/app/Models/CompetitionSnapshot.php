<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompetitionSnapshot extends Model
{
    protected $table = 'competition_snapshots';

    public $timestamps = true;

    protected $fillable = [
        'competition_id',
        'season_id',
        'import_id',
        'build_status',
        'published_at',
        'retired_at',
        'quality_summary',
    ];

    protected function casts(): array
    {
        return [
            'quality_summary' => 'array',
            'season_id' => 'integer',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function teams()
    {
        return $this->hasMany(SnapshotTeam::class, 'snapshot_id');
    }

    public function season()
    {
        return $this->belongsTo(CompetitionSeason::class, 'season_id');
    }

    public function import()
    {
        return $this->belongsTo(Import::class);
    }

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }
}
