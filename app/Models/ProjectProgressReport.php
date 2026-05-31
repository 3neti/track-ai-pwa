<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectProgressReport extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectProgressReportFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_EVALUATED = 'evaluated';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'project_id',
        'user_id',
        'contract_id',
        'current_milestone',
        'saras_process_id',
        'saras_workflow_run_id',
        'previous_progress_file_ids',
        'current_progress_file_ids',
        'remarks',
        'progress_status',
        'completion_status',
        'certificate_file_id',
        'raw_saras_response',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_progress_file_ids' => 'array',
            'current_progress_file_ids' => 'array',
            'raw_saras_response' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDraft(): bool
    {
        return $this->progress_status === self::STATUS_DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->progress_status === self::STATUS_SUBMITTED;
    }

    public function isProcessing(): bool
    {
        return $this->progress_status === self::STATUS_PROCESSING;
    }

    public function isTerminal(): bool
    {
        return in_array($this->progress_status, [self::STATUS_EVALUATED, self::STATUS_FAILED]);
    }
}
