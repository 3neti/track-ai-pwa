<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FaceRegistrationStatusRequest;
use App\Services\FaceAuth\SarasFaceRegistrationStatusService;
use Illuminate\Http\JsonResponse;

class FaceRegistrationStatusController extends Controller
{
    public function __construct(
        private readonly SarasFaceRegistrationStatusService $statusService,
    ) {}

    public function show(FaceRegistrationStatusRequest $request): JsonResponse
    {
        $result = $this->statusService->check($request->string('email')->toString());

        return response()->json([
            'ok' => $result['ok'],
            'face_registration_enabled' => $result['face_registration_enabled'],
            'auth_strategy' => $result['auth_strategy'] ?? null,
            'face_registered' => $result['face_registered'] ?? null,
            'face_registration_required' => $result['face_registration_required'] ?? null,
        ]);
    }
}
