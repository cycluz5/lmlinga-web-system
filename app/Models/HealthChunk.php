<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HealthChunk extends Model
{
    protected $fillable = [
        'language',
        'category',
        'source_file',
        'chunk_index',
        'content',
        'embedding',
    ];

    protected $casts = [
        'embedding' => 'array',
    ];
}