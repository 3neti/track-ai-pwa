<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceSession extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_AUTO_CLOSED = 'auto_closed';

    public const AUTO_CLOSE_REASON_END_OF_DAY = 'end_of_day';

    public const AUTO_CLOSE_REASON_PREVIOUS_DAY = 'previous_day_unclosed';

    protected $fillable = [
        'user_id',
        'project_external_id',
        'check_in_at',
        'check_in_latitude',
        'check_in_longitude',
        'check_in_remarks',
        'check_out_at',
        'check_out_latitude',
        'check_out_longitude',
        'check_out_remarks',
        'status',
        'auto_closed_reason',
    ];

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'check_in_latitude' => 'decimal:7',
            'check_in_longitude' => 'decimal:7',
            'check_out_latitude' => 'decimal:7',
            'check_out_longitude' => 'decimal:7',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if this session is currently open (checked in, not checked out).
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * Check if this session was auto-closed due to a missed checkout.
     */
    public function wasAutoClosed(): bool
    {
        return $this->status === self::STATUS_AUTO_CLOSED;
    }

    /**
     * Get the duration of this session in minutes.
     * Returns null if session is still open.
     */
    public function getDurationMinutes(): ?int
    {
        if (! $this->check_out_at) {
            return null;
        }

        return (int) $this->check_in_at->diffInMinutes($this->check_out_at);
    }

    /**
     * Scope to get open sessions.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Scope to get sessions for a specific user and project.
     */
    public function scopeForUserAndProject($query, int $userId, string $projectExternalId)
    {
        return $query->where('user_id', $userId)
            ->where('project_external_id', $projectExternalId);
    }
}
