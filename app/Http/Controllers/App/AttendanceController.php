<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\CheckInRequest;
use App\Http\Requests\App\CheckOutRequest;
use App\Services\TrackAI\AttendanceService;
use App\Services\TrackAI\AttendanceSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendanceService,
        protected AttendanceSessionService $sessionService,
    ) {}

    /**
     * Display the attendance page.
     */
    public function index(): Response
    {
        $projects = \App\Models\Project::orderBy('name')->get();

        return Inertia::render('app/Attendance', [
            'projects' => $projects,
        ]);
    }

    /**
     * Get current attendance status for a project.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $contractId = $request->query('contract_id');

        if (! $contractId) {
            return response()->json([
                'success' => false,
                'message' => 'contract_id is required',
            ], 422);
        }

        $status = $this->sessionService->getStatus($user->id, $contractId);

        return response()->json([
            'success' => true,
            'attendance_status' => $status['status'],
            'session' => $status['session'] ? [
                'id' => $status['session']->id,
                'check_in_at' => $status['session']->check_in_at->toIso8601String(),
                'check_in_latitude' => $status['session']->check_in_latitude,
                'check_in_longitude' => $status['session']->check_in_longitude,
            ] : null,
            'auto_closed_session' => $status['auto_closed_session'] ? [
                'id' => $status['auto_closed_session']->id,
                'check_in_at' => $status['auto_closed_session']->check_in_at->toIso8601String(),
                'reason' => $status['auto_closed_session']->auto_closed_reason,
            ] : null,
        ]);
    }

    /**
     * Record check-in.
     */
    public function checkIn(CheckInRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $result = $this->attendanceService->checkIn(
            user: $user,
            contractId: $validated['contract_id'],
            latitude: $validated['latitude'],
            longitude: $validated['longitude'],
            remarks: $validated['remarks'] ?? null,
            ipAddress: $request->ip(),
            clientRequestId: $validated['client_request_id'] ?? null,
        );

        $response = $result['response'];

        return response()->json([
            'success' => $response->success,
            'entry_id' => $response->entryId,
            'message' => $response->message,
            'attendance_status' => $result['attendance_status'],
            'session' => $result['session'] ? [
                'id' => $result['session']->id,
                'check_in_at' => $result['session']->check_in_at->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * Record check-out.
     */
    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $result = $this->attendanceService->checkOut(
            user: $user,
            contractId: $validated['contract_id'],
            latitude: $validated['latitude'],
            longitude: $validated['longitude'],
            remarks: $validated['remarks'] ?? null,
            ipAddress: $request->ip(),
            clientRequestId: $validated['client_request_id'] ?? null,
        );

        $response = $result['response'];

        return response()->json([
            'success' => $response->success,
            'entry_id' => $response->entryId,
            'message' => $response->message,
            'attendance_status' => $result['attendance_status'],
            'session' => $result['session'] ? [
                'id' => $result['session']->id,
                'check_in_at' => $result['session']->check_in_at->toIso8601String(),
                'check_out_at' => $result['session']->check_out_at?->toIso8601String(),
                'duration_minutes' => $result['session']->getDurationMinutes(),
            ] : null,
        ]);
    }
}
