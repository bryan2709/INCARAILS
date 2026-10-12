<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $table = 'players';

    public $timestamps = true;

    protected $fillable = [
        'external_id',
        'name',
        'first_name',
        'last_name',
        'date_of_birth',
        'nationality',
        'source_updated_at',
        'source_profile',
        'source_fetched_at',
        'first_published_at',
    ];

    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'source_profile' => 'array',
            'source_fetched_at' => 'datetime',
            'first_published_at' => 'datetime',
        ];
    }
}
