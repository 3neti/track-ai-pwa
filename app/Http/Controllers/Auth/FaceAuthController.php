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
use Illuminate\Support\Str;

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
        $captureFeedback = $this->hypervergeCaptureFeedback($request);

        // Log the attempt
        $this->logAttempt(
            $user->id,
            $transactionId,
            $result->verified,
            $result->reason,
            $result->confidence,
            $result->details,
        );

        // If verified, log the user in
        if ($result->verified) {
            $this->storeSarasFaceToken($user, $result->details);

            Auth::login($user, remember: false);

            $request->session()->regenerate();
            $this->storeCaptureFeedback($request, $captureFeedback, $result->verified, $result->reason);

            return response()->json([
                'ok' => true,
                'verified' => true,
                'redirect' => route('app.projects'),
            ]);
        }

        $this->storeCaptureFeedback($request, $captureFeedback, $result->verified, $result->reason);

        // Return failure response
        return response()->json([
            'ok' => true,
            'verified' => false,
            'reason' => $result->reason,
            'details' => [
                ...$result->details,
                'registration_url' => $result->reason === 'not_enrolled'
                    ? route('login', ['username' => $username])
                    : null,
            ],
        ]);
    }

    private function logAttempt(
        int $userId,
        string $transactionId,
        bool $verified,
        string $reason,
        ?float $confidence,
        array $details,
    ): void {
        AuditLog::log($userId, 'face_login_attempt', null, [
            'transaction_id' => $transactionId,
            'result' => $verified ? 'verified' : 'not_verified',
            'reason' => $reason,
            'confidence' => $confidence,
            'provider' => config('face_auth.provider'),
            'status' => $details['status'] ?? null,
            'message' => $details['message'] ?? null,
            'error_code' => $details['error_code'] ?? null,
            'registration_required' => $details['registration_required'] ?? false,
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

    /**
     * @return array<string, mixed>|null
     */
    private function hypervergeCaptureFeedback(FaceLoginRequest $request): ?array
    {
        $payload = $request->input('hyperverge_capture_feedback');

        if (! is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        return [
            'status' => $this->shortFeedbackValue($decoded['status'] ?? null),
            'transactionId' => $this->shortFeedbackValue($decoded['transactionId'] ?? null, 160),
            'errorCode' => $this->shortFeedbackValue($decoded['errorCode'] ?? null),
            'errorMessage' => $this->shortFeedbackValue($decoded['errorMessage'] ?? null, 240),
            'latestModule' => $this->shortFeedbackValue($decoded['latestModule'] ?? null),
            'detailKeys' => $this->feedbackStringList($decoded['detailKeys'] ?? []),
            'imageFieldPaths' => $this->feedbackStringList($decoded['imageFieldPaths'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $feedback
     */
    private function storeCaptureFeedback(
        FaceLoginRequest $request,
        ?array $feedback,
        bool $sarasVerified,
        string $sarasReason,
    ): void {
        if ($feedback === null) {
            return;
        }

        $request->session()->put('hyperverge_capture_feedback.last', [
            ...$feedback,
            'captured_at' => now()->toIso8601String(),
            'capture_role' => 'image_capture_only',
            'saras_decision' => [
                'authority' => config('face_auth.provider') === 'saras'
                    ? 'saras_loginWithFace'
                    : (string) config('face_auth.provider'),
                'verified' => $sarasVerified,
                'reason' => $sarasReason,
            ],
        ]);
    }

    private function shortFeedbackValue(mixed $value, int $limit = 80): string|int|float|bool|null
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return Str::limit($value, $limit, '');
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function feedbackStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value))
            ->map(fn (string $value): string => Str::limit($value, 120, ''))
            ->take(20)
            ->values()
            ->all();
    }
}
