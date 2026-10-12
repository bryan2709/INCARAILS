<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SnapshotTeam extends Model
{
    protected $table = 'snapshot_teams';

    public $timestamps = false;

    protected $fillable = [
        'snapshot_id',
        'team_id',
        'name',
        'short_name',
        'crest_url',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function players()
    {
        return $this->hasMany(SnapshotPlayer::class, 'snapshot_team_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function snapshot()
    {
        return $this->belongsTo(CompetitionSnapshot::class, 'snapshot_id');
    }
}
