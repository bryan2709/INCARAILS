<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompetitionSeason extends Model
{
    protected $table = 'competition_seasons';

    public $timestamps = true;

    protected $fillable = [
        'competition_id',
        'external_id',
        'start_date',
        'end_date',
        'current_matchday',
        'stages',
    ];

    protected function casts(): array
    {
        return [
            'stages' => 'array',
            'external_id' => 'integer',
            'competition_id' => 'integer',
        ];
    }

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }
}
