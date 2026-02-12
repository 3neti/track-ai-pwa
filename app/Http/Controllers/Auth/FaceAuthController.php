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

        // Short-circuit for non-existent users: add randomized delay to prevent timing attacks
        // without burning API credits
        if ($user === null) {
            usleep(random_int(250000, 450000)); // 250-450ms delay

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
        int $userId,
        string $transactionId,
        bool $verified,
        string $reason,
        ?float $confidence,
    ): void {
        AuditLog::log($userId, 'face_login_attempt', null, [
            'transaction_id' => $transactionId,
            'result' => $verified ? 'verified' : 'not_verified',
            'reason' => $reason,
            'confidence' => $confidence,
        ]);
    }
}
