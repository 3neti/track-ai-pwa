<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FaceRegistrationRequest;
use App\Models\AuditLog;
use App\Services\FaceAuth\SarasFaceRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class FaceRegistrationController extends Controller
{
    public function __construct(
        private readonly SarasFaceRegistrationService $registrationService,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('auth/FaceRegister', [
            'username' => $request->user()?->email ?? $request->user()?->username,
        ]);
    }

    public function store(FaceRegistrationRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->saras_access_token) {
            return response()->json([
                'ok' => false,
                'message' => 'Please log in with your temporary Saras password first.',
            ], 422);
        }

        $result = $this->registrationService->register(
            $user,
            $request->file('selfie'),
            $request->file('document'),
        );

        AuditLog::log($user->id, 'face_registration_attempt', null, [
            'provider' => 'saras',
            'result' => $result['ok'] ? 'registered' : 'failed',
        ]);

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => $result['message'] ?? 'Face registration failed.',
            ], 422);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'ok' => true,
            'redirect' => route('login'),
            'message' => 'Face registration complete. Please log in with face.',
        ]);
    }
}
