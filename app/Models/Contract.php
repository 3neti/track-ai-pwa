<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    /** @use HasFactory<\Database\Factories\ContractFactory> */
    use HasFactory;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_PENDING = 'pending';

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_UNKNOWN = 'unknown';

    protected $fillable = [
        'saras_process_id',
        'name',
        'display_number',
        'milestones',
        'certificate_status',
        'certificate_file_id',
        'certificate_url',
        'raw_saras_payload',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'milestones' => 'array',
            'raw_saras_payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function isCertificateAvailable(): bool
    {
        return $this->certificate_status === self::STATUS_AVAILABLE;
    }
}
