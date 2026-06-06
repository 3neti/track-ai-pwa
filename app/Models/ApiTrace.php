<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiTrace extends Model
{
    protected $fillable = [
        'trace_id', 'provider', 'operation', 'method', 'endpoint',
        'request_body', 'response_body', 'status_code', 'duration_ms',
        'user_id', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'request_body' => 'array',
            'response_body' => 'array',
            'duration_ms' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSuccess(): bool
    {
        return $this->status_code >= 200 && $this->status_code < 300;
    }
}
