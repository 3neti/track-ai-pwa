<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'project_external_id',
        'metadata_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create an audit log entry.
     */
    public static function log(int $userId, string $action, ?string $projectExternalId = null, ?array $metadata = null): self
    {
        return static::create([
            'user_id' => $userId,
            'action' => $action,
            'project_external_id' => $projectExternalId,
            'metadata_json' => $metadata,
        ]);
    }
}
