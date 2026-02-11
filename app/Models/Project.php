<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'name',
        'description',
        'cached_at',
    ];

    protected function casts(): array
    {
        return [
            'cached_at' => 'datetime',
        ];
    }
}
