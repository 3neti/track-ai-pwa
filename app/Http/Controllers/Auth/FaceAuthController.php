<?php

namespace App\Http\Controllers\Auth;

use App\Contracts\FaceAuthProviderInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FaceLoginRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

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
        $user = User::where('username', $username)
            ->orWhere('email', $username)
            ->first();

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
            $this->storeSarasFaceToken($user, $result->details);

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

    /**
     * @param  array<string, mixed>  $details
     */
    private function storeSarasFaceToken(User $user, array $details): void
    {
        $token = $details['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            return;
        }

        $expiresIn = is_numeric($details['expires_in'] ?? null)
            ? (int) $details['expires_in']
            : 3600;

        $sarasUser = is_array($details['user'] ?? null) ? $details['user'] : [];
        $tenant = is_array($sarasUser['tenantId'] ?? null) ? $sarasUser['tenantId'] : [];

        $user->forceFill([
            'password' => $user->password ?: Hash::make(str()->random(32)),
            'saras_user_id' => $sarasUser['id'] ?? $user->saras_user_id,
            'tenant_id' => $tenant['id'] ?? $user->tenant_id,
            'tenant_name' => $tenant['name'] ?? $user->tenant_name,
            'saras_access_token' => $token,
            'saras_token_expires_at' => now()->addSeconds(max($expiresIn - 60, 60)),
        ])->save();
    }
}
