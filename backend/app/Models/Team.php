<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $table = 'teams';

    public $timestamps = true;

    protected $fillable = [
        'external_id',
        'area_id',
        'name',
        'short_name',
        'tla',
        'crest_url',
        'source_updated_at',
        'source_profile',
        'source_fetched_at',
        'first_published_at',
    ];

    protected $casts = [
        'source_updated_at' => 'datetime',
        'source_profile' => 'array',
        'source_fetched_at' => 'datetime',
        'first_published_at' => 'datetime',
    ];

    public function area()
    {
        return $this->belongsTo(Area::class);
    }
}
