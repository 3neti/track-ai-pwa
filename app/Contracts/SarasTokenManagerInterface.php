<?php

namespace App\Contracts;

interface SarasTokenManagerInterface
{
    /**
     * Get a valid access token, fetching a new one if necessary.
     *
     * @throws \App\Exceptions\SarasApiException
     */
    public function getAccessToken(): string;

    /**
     * Invalidate the cached token, forcing a fresh login on next request.
     */
    public function invalidateToken(): void;
}
