<?php

namespace App\Services\TrackAI;

use App\Contracts\SarasClientInterface;
use App\Exceptions\SarasApiException;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Location\LocationTrustService;
use App\Services\Saras\DTO\ProcessResponse;
use App\Services\Saras\SarasProjectContextResolver;
use Illuminate\Support\Str;

class AttendanceService
{
    public function __construct(
        protected SarasClientInterface $sarasClient,
        protected AttendanceSessionService $sessionService,
        protected LocationTrustService $locationTrustService,
        protected SarasProjectContextResolver $contextResolver,
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
        array $locationEvidence = [],
    ): array {
        $userId = $user->id;
        $locationAssessment = $this->locationTrustService->assess($user, array_merge($locationEvidence, [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]));

        if ($locationAssessment['status'] === 'rejected') {
            return [
                'response' => ProcessResponse::fromArray([
                    'success' => false,
                    'entry_id' => null,
                    'message' => 'Location verification failed. Please disable mock location tools and try again.',
                ]),
                'session' => null,
                'attendance_status' => 'checked_out',
                'location_assessment' => $locationAssessment,
            ];
        }

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
                'location_assessment' => $locationAssessment,
            ];
        }

        // Use client_request_id for deterministic idempotency (offline replay safe)
        $idempotencyKey = $clientRequestId ?? $this->generateIdempotencyKey($userId, $contractId, 'check_in');

        // Resolve contract ID - use default if not provided by Saras yet
        $resolvedContractId = $contractId ?: config('saras.default_contract_id');

        try {
            $fields = [
                'userId' => $user->saras_user_id,
                'contractId' => $resolvedContractId,
                'ipAddressCheckIn' => $ipAddress ?? '',
                'geoLocationCheckIn' => "{$latitude},{$longitude}",
                'date' => now('Asia/Manila')->toDateString(),
                'checkInTime' => now('Asia/Manila')->toIso8601String(),
                'remarks' => $remarks ?? '',
            ];

            if (config('saras.location_trust.send_to_saras', false)) {
                $fields += [
                    'geoAccuracyCheckIn' => $locationAssessment['evidence']['accuracy_meters'] ?? '',
                    'locationTrustCheckIn' => $locationAssessment['status'],
                    'locationTrustReasonsCheckIn' => implode(',', $locationAssessment['reasons']),
                ];
            }

            $response = $this->sarasClient->createProcess(
                subProjectId: $this->contextResolver->subProjectId('attendance', user: $user),
                fields: $fields,
                idempotencyKey: $idempotencyKey,
                parentProcessId: $resolvedContractId,
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
                'location_assessment' => $locationAssessment,
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
                $locationAssessment,
            );

            AuditLog::log($userId, 'attendance_check_in', $contractId, [
                'entry_id' => $response->entryId,
                'saras_process_id' => $response->processId,
                'idempotency_key' => $idempotencyKey,
                'session_id' => $session->id,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'location_status' => $locationAssessment['status'],
                'location_reasons' => $locationAssessment['reasons'],
            ]);
        }

        return [
            'response' => $response,
            'session' => $session,
            'attendance_status' => $session ? 'checked_in' : 'checked_out',
            'location_assessment' => $locationAssessment,
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
        array $locationEvidence = [],
    ): array {
        $userId = $user->id;
        $locationAssessment = $this->locationTrustService->assess($user, array_merge($locationEvidence, [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]));

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
                'location_assessment' => $locationAssessment,
            ];
        }

        if ($locationAssessment['status'] === 'rejected') {
            return [
                'response' => ProcessResponse::fromArray([
                    'success' => false,
                    'entry_id' => null,
                    'message' => 'Location verification failed. Please disable mock location tools and try again.',
                ]),
                'session' => $session,
                'attendance_status' => 'checked_in',
                'location_assessment' => $locationAssessment,
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

                $updates = [
                    'ipAddressCheckOut' => $ipAddress ?? '',
                    'geoLocationCheckOut' => "{$latitude},{$longitude}",
                    'checkOutTime' => now('Asia/Manila')->toIso8601String(),
                    'remarks' => $combinedRemarks,
                ];

                if (config('saras.location_trust.send_to_saras', false)) {
                    $updates += [
                        'geoAccuracyCheckOut' => $locationAssessment['evidence']['accuracy_meters'] ?? '',
                        'locationTrustCheckOut' => $locationAssessment['status'],
                        'locationTrustReasonsCheckOut' => implode(',', $locationAssessment['reasons']),
                    ];
                }

                $this->sarasClient->updateProcessField(
                    processId: $session->saras_process_id,
                    subProjectId: $this->contextResolver->subProjectId('attendance', user: $user),
                    updates: $updates,
                );

                $response = ProcessResponse::fromArray([
                    'success' => true,
                    'processId' => $session->saras_process_id,
                    'entryId' => $session->saras_process_id,
                    'message' => 'Check-out recorded (updated existing process)',
                ]);
            } else {
                // Fallback: create new process if no saras_process_id stored
                $fields = [
                    'userId' => $user->saras_user_id,
                    'contractId' => $resolvedContractId,
                    'ipAddressCheckOut' => $ipAddress ?? '',
                    'geoLocationCheckOut' => "{$latitude},{$longitude}",
                    'date' => now('Asia/Manila')->toDateString(),
                    'checkOutTime' => now('Asia/Manila')->toIso8601String(),
                    'remarks' => $remarks ?? '',
                ];

                if (config('saras.location_trust.send_to_saras', false)) {
                    $fields += [
                        'geoAccuracyCheckOut' => $locationAssessment['evidence']['accuracy_meters'] ?? '',
                        'locationTrustCheckOut' => $locationAssessment['status'],
                        'locationTrustReasonsCheckOut' => implode(',', $locationAssessment['reasons']),
                    ];
                }

                $response = $this->sarasClient->createProcess(
                    subProjectId: $this->contextResolver->subProjectId('attendance', user: $user),
                    fields: $fields,
                    idempotencyKey: $idempotencyKey,
                    parentProcessId: $resolvedContractId,
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
                'location_assessment' => $locationAssessment,
            ];
        }

        if ($response->success) {
            // Close the local session
            $session = $this->sessionService->closeSession(
                $session,
                $latitude,
                $longitude,
                $remarks,
                $locationAssessment,
            );

            AuditLog::log($userId, 'attendance_check_out', $contractId, [
                'entry_id' => $response->entryId,
                'saras_process_id' => $session->saras_process_id,
                'idempotency_key' => $idempotencyKey,
                'session_id' => $session->id,
                'duration_minutes' => $session->getDurationMinutes(),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'location_status' => $locationAssessment['status'],
                'location_reasons' => $locationAssessment['reasons'],
            ]);
        }

        return [
            'response' => $response,
            'session' => $session,
            'attendance_status' => 'checked_out',
            'location_assessment' => $locationAssessment,
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
