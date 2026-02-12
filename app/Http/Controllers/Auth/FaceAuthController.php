<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\FaceAuthProviderInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FaceLoginRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class FaceAuthController extends Controller
{
    public function __construct(
        private readonly FaceAuthProviderInterface $faceAuth,
    ) {}

    public function verify(FaceLoginRequest $request): JsonResponse
    {
        $username = $request->input('username');
        $selfie = $request->file('selfie');
        $transactionId = $request->transactionId();

        // Look up user - don't leak existence in response
        $user = User::where('username', $username)->first();
        $userExists = $user !== null;

        // If user doesn't exist, still call provider to avoid timing attacks
        // but use a dummy result
        if (! $userExists) {
            $result = $this->faceAuth->verify($username, $selfie, $transactionId);

            // Log attempt without revealing user doesn't exist
            $this->logAttempt(null, $transactionId, false, 'not_matched', null, $userExists);

            return response()->json([
                'ok' => true,
                'verified' => false,
                'reason' => 'not_matched',
                'details' => ['message' => 'Face does not match the enrolled reference.'],
            ]);
        }

        // Perform verification
        $result = $this->faceAuth->verify($username, $selfie, $transactionId);

        // Log the attempt
        $this->logAttempt(
            $user->id,
            $transactionId,
            $result->verified,
            $result->reason,
            $result->confidence,
            $userExists
        );

        // If verified, log the user in
        if ($result->verified) {
            Auth::login($user, remember: false);

            $request->session()->regenerate();

            return response()->json([
                'ok' => true,
                'verified' => true,
                'redirect' => route('app.projects'),
            ]);
        }

        // Return failure response
        return response()->json([
            'ok' => true,
            'verified' => false,
            'reason' => $result->reason,
            'details' => $result->details,
        ]);
    }

    private function logAttempt(
        ?int $userId,
        string $transactionId,
        bool $verified,
        string $reason,
        ?float $confidence,
        bool $userExists,
    ): void {
        // Only log if we have a user ID
        if ($userId === null) {
            return;
        }

        AuditLog::log($userId, 'face_login_attempt', null, [
            'transaction_id' => $transactionId,
            'result' => $verified ? 'verified' : 'not_verified',
            'reason' => $reason,
            'confidence' => $confidence,
            'username_exists' => $userExists,
        ]);
    }
}
