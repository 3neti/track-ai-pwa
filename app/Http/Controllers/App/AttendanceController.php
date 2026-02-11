<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\CheckInRequest;
use App\Http\Requests\App\CheckOutRequest;
use App\Services\TrackAI\AttendanceService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendanceService,
    ) {}

    /**
     * Display the attendance page.
     */
    public function index(): Response
    {
        return Inertia::render('app/Attendance');
    }

    /**
     * Record check-in.
     */
    public function checkIn(CheckInRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $response = $this->attendanceService->checkIn(
            userId: $user->id,
            contractId: $validated['contract_id'],
            latitude: $validated['latitude'],
            longitude: $validated['longitude'],
            remarks: $validated['remarks'] ?? null,
            ipAddress: $request->ip(),
        );

        return response()->json([
            'success' => $response->success,
            'entry_id' => $response->entryId,
            'message' => $response->message,
        ]);
    }

    /**
     * Record check-out.
     */
    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $response = $this->attendanceService->checkOut(
            userId: $user->id,
            contractId: $validated['contract_id'],
            latitude: $validated['latitude'],
            longitude: $validated['longitude'],
            remarks: $validated['remarks'] ?? null,
            ipAddress: $request->ip(),
        );

        return response()->json([
            'success' => $response->success,
            'entry_id' => $response->entryId,
            'message' => $response->message,
        ]);
    }
}
