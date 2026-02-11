<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Project model representing DPWH construction projects.
 *
 * NOTE: The `external_id` field serves as the `contract_id` for Saras API integration.
 * When the frontend sends `contract_id` in API requests, it uses the project's `external_id`.
 * This simplification avoids a separate contract_id column while maintaining API compatibility.
 */
class Project extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'external_id',
        'name',
        'description',
        'status',
        'cached_at',
    ];

    /**
     * Get the contract_id (alias for external_id for Saras API compatibility).
     */
    public function getContractIdAttribute(): string
    {
        return $this->external_id;
    }

    protected function casts(): array
    {
        return [
            'cached_at' => 'datetime',
        ];
    }

    /**
     * Check if the project is closed.
     */
    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Check if the project is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
