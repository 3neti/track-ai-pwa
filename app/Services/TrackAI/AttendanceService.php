<?php

namespace App\Services\TrackAI;

use App\Contracts\SarasClientInterface;
use App\Exceptions\SarasApiException;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\User;
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
        User $user,
        string $contractId,
        float $latitude,
        float $longitude,
        ?string $remarks = null,
        ?string $ipAddress = null,
        ?string $clientRequestId = null,
    ): array {
        $userId = $user->id;

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
                    'userId' => $user->saras_user_id,
                    'contractId' => $resolvedContractId,
                    'ipAddressCheckIn' => $ipAddress ?? '',
                    'geoLocationCheckIn' => "{$latitude},{$longitude}",
                    'date' => now('Asia/Manila')->toDateString(),
                    'checkInTime' => now('Asia/Manila')->toIso8601String(),
                    'remarks' => $remarks ?? '',
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
            // Create local session with Saras process ID
            $session = $this->sessionService->openSession(
                $userId,
                $contractId,
                $latitude,
                $longitude,
                $remarks,
                $response->processId,
            );

            AuditLog::log($userId, 'attendance_check_in', $contractId, [
                'entry_id' => $response->entryId,
                'saras_process_id' => $response->processId,
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
        User $user,
        string $contractId,
        float $latitude,
        float $longitude,
        ?string $remarks = null,
        ?string $ipAddress = null,
        ?string $clientRequestId = null,
    ): array {
        $userId = $user->id;

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
            // Update the existing check-in process with check-out fields
            if ($session->saras_process_id) {
                // Combine check-in and check-out remarks into one field
                $combinedRemarks = $this->combineRemarks($session->check_in_remarks, $remarks);

                $this->sarasClient->updateProcessField(
                    processId: $session->saras_process_id,
                    subProjectId: config('saras.subproject_ids.attendance'),
                    updates: [
                        'ipAddressCheckOut' => $ipAddress ?? '',
                        'geoLocationCheckOut' => "{$latitude},{$longitude}",
                        'checkOutTime' => now('Asia/Manila')->toIso8601String(),
                        'remarks' => $combinedRemarks,
                    ],
                );

                $response = ProcessResponse::fromArray([
                    'success' => true,
                    'processId' => $session->saras_process_id,
                    'entryId' => $session->saras_process_id,
                    'message' => 'Check-out recorded (updated existing process)',
                ]);
            } else {
                // Fallback: create new process if no saras_process_id stored
                $response = $this->sarasClient->createProcess(
                    subProjectId: config('saras.subproject_ids.attendance'),
                    fields: [
                        'userId' => $user->saras_user_id,
                        'contractId' => $resolvedContractId,
                        'ipAddressCheckOut' => $ipAddress ?? '',
                        'geoLocationCheckOut' => "{$latitude},{$longitude}",
                        'date' => now('Asia/Manila')->toDateString(),
                        'checkOutTime' => now('Asia/Manila')->toIso8601String(),
                        'remarks' => $remarks ?? '',
                    ],
                    idempotencyKey: $idempotencyKey,
                );
            }
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
                'saras_process_id' => $session->saras_process_id,
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
     * Combine check-in and check-out remarks into a single string.
     */
    protected function combineRemarks(?string $checkInRemarks, ?string $checkOutRemarks): string
    {
        $checkIn = trim($checkInRemarks ?? '');
        $checkOut = trim($checkOutRemarks ?? '');

        if ($checkIn && $checkOut) {
            return "check in remarks: {$checkIn}\ncheck out remarks: {$checkOut}";
        }

        if ($checkOut) {
            return $checkOut;
        }

        return $checkIn;
    }

    protected function generateIdempotencyKey(int $userId, string $contractId, string $action): string
    {
        $date = now()->format('Y-m-d');

        return "attendance_{$action}_{$userId}_{$contractId}_{$date}_".Str::random(8);
    }
}
