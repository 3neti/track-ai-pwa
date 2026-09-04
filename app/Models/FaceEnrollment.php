<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaceEnrollment extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'disk',
        'path',
        'status',
        'metadata',
        'enrolled_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'enrolled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
