<?php

namespace App\Services\TrackAI;

use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Services\Saras\DTO\EntryResponse;
use App\Services\Saras\SarasClient;
use Illuminate\Support\Str;

class AttendanceService
{
    public function __construct(
        protected SarasClient $sarasClient,
        protected AttendanceSessionService $sessionService,
    ) {}

    /**
     * Record check-in for a user.
     *
     * @return array{response: EntryResponse, session: ?AttendanceSession, attendance_status: string}
     */
    public function checkIn(
        int $userId,
        string $contractId,
        float $latitude,
        float $longitude,
        ?string $remarks = null,
        ?string $ipAddress = null,
    ): array {
        // Auto-close any orphaned sessions from previous days
        $this->sessionService->autoClosePreviousDaySessions($userId);

        // Check if user can check in
        if (! $this->sessionService->canCheckIn($userId, $contractId)) {
            return [
                'response' => EntryResponse::fromArray([
                    'success' => false,
                    'entry_id' => null,
                    'message' => 'Already checked in to this project. Please check out first.',
                ]),
                'session' => $this->sessionService->getOpenSession($userId, $contractId),
                'attendance_status' => 'checked_in',
            ];
        }

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

        $session = null;

        if ($response->success) {
            // Create local session
            $session = $this->sessionService->openSession(
                $userId,
                $contractId,
                $latitude,
                $longitude,
                $remarks
            );

            AuditLog::log($userId, 'attendance_check_in', $contractId, [
                'entry_id' => $response->entryId,
                'idempotency_key' => $idempotencyKey,
                'session_id' => $session->id,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }

        return [
            'response' => $response,
            'session' => $session,
            'attendance_status' => $session ? 'checked_in' : 'checked_out',
        ];
    }

    /**
     * Record check-out for a user.
     *
     * @return array{response: EntryResponse, session: ?AttendanceSession, attendance_status: string}
     */
    public function checkOut(
        int $userId,
        string $contractId,
        float $latitude,
        float $longitude,
        ?string $remarks = null,
        ?string $ipAddress = null,
    ): array {
        // Get the open session
        $session = $this->sessionService->getOpenSession($userId, $contractId);

        // Check if user can check out
        if (! $session) {
            return [
                'response' => EntryResponse::fromArray([
                    'success' => false,
                    'entry_id' => null,
                    'message' => 'Not checked in to this project. Please check in first.',
                ]),
                'session' => null,
                'attendance_status' => 'checked_out',
            ];
        }

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
            // Close the local session
            $session = $this->sessionService->closeSession(
                $session,
                $latitude,
                $longitude,
                $remarks
            );

            AuditLog::log($userId, 'attendance_check_out', $contractId, [
                'entry_id' => $response->entryId,
                'idempotency_key' => $idempotencyKey,
                'session_id' => $session->id,
                'duration_minutes' => $session->getDurationMinutes(),
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);
        }

        return [
            'response' => $response,
            'session' => $session,
            'attendance_status' => 'checked_out',
        ];
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
