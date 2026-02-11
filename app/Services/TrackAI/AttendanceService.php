<?php

namespace App\Services\TrackAI;

use App\Models\AuditLog;
use App\Services\Saras\DTO\EntryResponse;
use App\Services\Saras\SarasClient;
use Illuminate\Support\Str;

class AttendanceService
{
    public function __construct(
        protected SarasClient $sarasClient,
    ) {}

    /**
     * Record check-in for a user.
     */
    public function checkIn(
        int $userId,
        string $contractId,
        float $latitude,
        float $longitude,
        ?string $remarks = null,
        ?string $ipAddress = null,
    ): EntryResponse {
        $idempotencyKey = $this->generateIdempotencyKey($userId, $contractId, 'check_in');

        $response = $this->sarasClient->createAnEntry([
            'type' => 'attendance_check_in',
            'user_id' => $userId,
            'contract_id' => $contractId,
            'geo_location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            'ip_address' => $ipAddress,
            'remarks' => $remarks,
            'timestamp' => now()->toIso8601String(),
        ], $idempotencyKey);

        if ($response->success) {
            AuditLog::log($userId, 'attendance_check_in', $contractId, [
                'entry_id' => $response->entryId,
                'idempotency_key' => $idempotencyKey,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }

        return $response;
    }

    /**
     * Record check-out for a user.
     */
    public function checkOut(
        int $userId,
        string $contractId,
        float $latitude,
        float $longitude,
        ?string $remarks = null,
        ?string $ipAddress = null,
    ): EntryResponse {
        $idempotencyKey = $this->generateIdempotencyKey($userId, $contractId, 'check_out');

        $response = $this->sarasClient->createAnEntry([
            'type' => 'attendance_check_out',
            'user_id' => $userId,
            'contract_id' => $contractId,
            'geo_location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ],
            'ip_address' => $ipAddress,
            'remarks' => $remarks,
            'timestamp' => now()->toIso8601String(),
        ], $idempotencyKey);

        if ($response->success) {
            AuditLog::log($userId, 'attendance_check_out', $contractId, [
                'entry_id' => $response->entryId,
                'idempotency_key' => $idempotencyKey,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }

        return $response;
    }

    /**
     * Generate idempotency key for attendance actions.
     */
    protected function generateIdempotencyKey(int $userId, string $contractId, string $action): string
    {
        $date = now()->format('Y-m-d');

        return "attendance_{$action}_{$userId}_{$contractId}_{$date}_".Str::random(8);
    }
}
