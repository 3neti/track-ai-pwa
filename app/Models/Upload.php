<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Upload extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'project_id',
        'user_id',
        'contract_id',
        'entry_id',
        'remote_file_id',
        'title',
        'remarks',
        'document_type',
        'tags',
        'mime',
        'size',
        'status',
        'last_error',
        'client_request_id',
        'locked_at',
        'locked_reason',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'size' => 'integer',
            'locked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Check if the upload is in a pending state (not yet synced to Saras).
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the upload has been successfully uploaded to Saras.
     */
    public function isUploaded(): bool
    {
        return $this->status === self::STATUS_UPLOADED;
    }

    /**
     * Check if the upload failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the upload is locked (cannot be edited/deleted).
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Check if the upload can be edited.
     */
    public function isEditable(): bool
    {
        if ($this->isLocked()) {
            return false;
        }

        if ($this->status === self::STATUS_DELETED) {
            return false;
        }

        if ($this->project && $this->project->isClosed()) {
            return false;
        }

        return true;
    }

    /**
     * Check if the upload can be deleted.
     */
    public function isDeletable(): bool
    {
        return $this->isEditable();
    }

    /**
     * Check if the upload can be retried.
     */
    public function isRetryable(): bool
    {
        return $this->status === self::STATUS_FAILED && ! $this->isLocked();
    }

    /**
     * Lock the upload with a reason.
     */
    public function lock(string $reason): void
    {
        $this->update([
            'locked_at' => now(),
            'locked_reason' => $reason,
        ]);
    }

    /**
     * Mark the upload with a new status.
     */
    public function markAs(string $status, ?string $error = null): void
    {
        $this->update([
            'status' => $status,
            'last_error' => $error,
        ]);
    }

    /**
     * Scope to filter by project.
     */
    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope to filter by contract_id.
     */
    public function scopeForContract($query, string $contractId)
    {
        return $query->where('contract_id', $contractId);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter unlocked uploads.
     */
    public function scopeNotLocked($query)
    {
        return $query->whereNull('locked_at');
    }

    /**
     * Scope to filter by tag.
     */
    public function scopeWithTag($query, string $tag)
    {
        return $query->whereJsonContains('tags', $tag);
    }

    /**
     * Scope to search by title or remarks.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
                ->orWhere('remarks', 'like', "%{$search}%");
        });
    }
}
