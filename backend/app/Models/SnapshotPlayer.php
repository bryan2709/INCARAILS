<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SnapshotPlayer extends Model
{
    protected $table = 'snapshot_players';

    public $timestamps = false;

    protected $fillable = [
        'snapshot_team_id',
        'player_id',
        'name',
        'position',
        'shirt_number',
    ];

    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    public function snapshotTeam()
    {
        return $this->belongsTo(SnapshotTeam::class, 'snapshot_team_id');
    }
}
