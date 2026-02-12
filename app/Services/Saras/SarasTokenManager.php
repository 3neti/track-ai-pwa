<?php

namespace App\Services\Saras;

use App\Contracts\SarasTokenManagerInterface;
use App\Exceptions\SarasApiException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Manages Saras API tokens using the authenticated user's stored token.
 *
 * Tokens are obtained during login and stored in the users table.
 * This eliminates the need for a service account.
 */
class SarasTokenManager implements SarasTokenManagerInterface
{
    /**
     * Get a valid access token from the authenticated user.
     *
     * @throws SarasApiException
     */
    public function getAccessToken(): string
    {
        $user = Auth::user();

        if (! $user) {
            Log::error('Saras API: No authenticated user for token retrieval');
            throw SarasApiException::authFailed('No authenticated user');
        }

        $token = $user->saras_access_token;
        $expiresAt = $user->saras_token_expires_at;

        if (! $token) {
            Log::error('Saras API: User has no Saras token', [
                'user_id' => $user->id,
            ]);
            throw SarasApiException::authFailed('User has no Saras token. Please log in again.');
        }

        // Check if token is expired
        if ($expiresAt && now()->greaterThan($expiresAt)) {
            Log::warning('Saras API: User token expired', [
                'user_id' => $user->id,
                'expired_at' => $expiresAt,
            ]);
            throw SarasApiException::authFailed('Saras token expired. Please log in again.');
        }

        return $token;
    }

    /**
     * Invalidate the current user's token.
     */
    public function invalidateToken(): void
    {
        $user = Auth::user();

        if ($user) {
            $user->update([
                'saras_access_token' => null,
                'saras_token_expires_at' => null,
            ]);

            Log::info('Saras API: User token invalidated', [
                'user_id' => $user->id,
            ]);
        }
    }
}
