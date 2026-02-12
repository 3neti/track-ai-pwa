<?php

namespace App\Contracts;

use App\Services\FaceAuth\DTO\FaceVerificationResult;
use Illuminate\Http\UploadedFile;

interface FaceAuthProviderInterface
{
    /**
     * Verify a user's face against their enrolled reference.
     */
    public function verify(string $username, UploadedFile $selfie, string $transactionId): FaceVerificationResult;
}
