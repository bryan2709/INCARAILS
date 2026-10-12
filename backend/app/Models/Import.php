<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Import extends Model
{
    protected $table = 'imports';

    public $timestamps = true;

    protected $fillable = [
        'competition_id',
        'team_id',
        'player_id',
        'season_id',
        'type',
        'status',
        'requested_by',
        'retry_of_import_id',
        'active_scope_key',
        'started_at',
        'completed_at',
        'error_code',
        'error_message',
        'quality_summary',
    ];

    protected function casts(): array
    {
        return [
            'quality_summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function steps()
    {
        return $this->hasMany(ImportStep::class);
    }
}
