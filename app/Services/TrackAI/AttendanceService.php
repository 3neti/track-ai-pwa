<?php

namespace App\Services\TrackAI;

use App\Contracts\SarasClientInterface;
use App\Exceptions\SarasApiException;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Services\Saras\DTO\ProcessResponse;
use Illuminate\Support\Str;

class AttendanceService
{
    public function __construct(
        protected SarasClientInterface $sarasClient,
        protected AttendanceSessionService $sessionService,
    ) {}

    /**
     * Record check-in for a user.
     *
     * @return array{response: ProcessResponse, session: ?AttendanceSession, attendance_status: string}
     */
    public function checkIn(
        int $userId,
        string $contractId,
        float $latitude,
        float $longitude,
        ?string $remarks = null,
        ?string $ipAddress = null,
        ?string $clientRequestId = null,
    ): array {
        // Auto-close any orphaned sessions from previous days
        $this->sessionService->autoClosePreviousDaySessions($userId);

        // Check if user can check in
        if (! $this->sessionService->canCheckIn($userId, $contractId)) {
            return [
                'response' => ProcessResponse::fromArray([
                    'success' => false,
                    'entry_id' => null,
                    'message' => 'Already checked in to this project. Please check out first.',
                ]),
                'session' => $this->sessionService->getOpenSession($userId, $contractId),
                'attendance_status' => 'checked_in',
            ];
        }

        // Use client_request_id for deterministic idempotency (offline replay safe)
        $idempotencyKey = $clientRequestId ?? $this->generateIdempotencyKey($userId, $contractId, 'check_in');

        // Resolve contract ID - use default if not provided by Saras yet
        $resolvedContractId = $contractId ?: config('saras.default_contract_id');

        try {
            $response = $this->sarasClient->createProcess(
                subProjectId: config('saras.subproject_ids.attendance'),
                fields: [
                    'userId' => $userId, // TODO: Use saras_user_id when available
                    'contractId' => $resolvedContractId,
                    'ipAddressCheckIn' => $ipAddress,
                    'geoLocationCheckIn' => "{$latitude},{$longitude}",
                    'date' => now()->toDateString(),
                    'checkInTime' => now()->toTimeString(),
                    'remarks' => $remarks,
                ],
                idempotencyKey: $idempotencyKey,
            );
        } catch (SarasApiException $e) {
            return [
                'response' => ProcessResponse::fromArray([
                    'success' => false,
                    'entry_id' => null,
                    'message' => $e->getMessage(),
                ]),
                'session' => null,
                'attendance_status' => 'checked_out',
            ];
        }

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
     * @return array{response: ProcessResponse, session: ?AttendanceSession, attendance_status: string}
     */
    public function checkOut(
        int $userId,
        string $contractId,
        float $latitude,
        float $longitude,
        ?string $remarks = null,
        ?string $ipAddress = null,
        ?string $clientRequestId = null,
    ): array {
        // Get the open session
        $session = $this->sessionService->getOpenSession($userId, $contractId);

        // Check if user can check out
        if (! $session) {
            return [
                'response' => ProcessResponse::fromArray([
                    'success' => false,
                    'entry_id' => null,
                    'message' => 'Not checked in to this project. Please check in first.',
                ]),
                'session' => null,
                'attendance_status' => 'checked_out',
            ];
        }

        // Use client_request_id for deterministic idempotency (offline replay safe)
        $idempotencyKey = $clientRequestId ?? $this->generateIdempotencyKey($userId, $contractId, 'check_out');

        // Resolve contract ID - use default if not provided by Saras yet
        $resolvedContractId = $contractId ?: config('saras.default_contract_id');

        try {
            $response = $this->sarasClient->createProcess(
                subProjectId: config('saras.subproject_ids.attendance'),
                fields: [
                    'userId' => $userId, // TODO: Use saras_user_id when available
                    'contractId' => $resolvedContractId,
                    'ipAddressCheckOut' => $ipAddress,
                    'geoLocationCheckOut' => "{$latitude},{$longitude}",
                    'date' => now()->toDateString(),
                    'checkOutTime' => now()->toTimeString(),
                    'remarks' => $remarks,
                ],
                idempotencyKey: $idempotencyKey,
            );
        } catch (SarasApiException $e) {
            return [
                'response' => ProcessResponse::fromArray([
                    'success' => false,
                    'entry_id' => null,
                    'message' => $e->getMessage(),
                ]),
                'session' => $session,
                'attendance_status' => 'checked_in',
            ];
        }

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
     * Used as fallback when client_request_id is not provided.
     * Note: This generates a random suffix, so it's NOT safe for offline replay.
     * Always prefer using client_request_id from the frontend.
     */
    protected function generateIdempotencyKey(int $userId, string $contractId, string $action): string
    {
        $date = now()->format('Y-m-d');

        return "attendance_{$action}_{$userId}_{$contractId}_{$date}_".Str::random(8);
    }
}
