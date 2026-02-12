<?php

use App\Exceptions\SarasApiException;
use App\Models\User;
use App\Services\Saras\SarasTokenManager;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

test('gets token from authenticated user', function () {
    $user = User::factory()->create([
        'saras_access_token' => 'valid_test_token_123',
        'saras_token_expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user);

    $tokenManager = new SarasTokenManager;
    $token = $tokenManager->getAccessToken();

    expect($token)->toBe('valid_test_token_123');
});

test('throws exception when no authenticated user', function () {
    $tokenManager = new SarasTokenManager;

    expect(fn () => $tokenManager->getAccessToken())
        ->toThrow(SarasApiException::class, 'No authenticated user');
});

test('throws exception when user has no token', function () {
    $user = User::factory()->create([
        'saras_access_token' => null,
        'saras_token_expires_at' => null,
    ]);

    $this->actingAs($user);

    $tokenManager = new SarasTokenManager;

    expect(fn () => $tokenManager->getAccessToken())
        ->toThrow(SarasApiException::class, 'User has no Saras token');
});

test('throws exception when token is expired', function () {
    $user = User::factory()->create([
        'saras_access_token' => 'expired_token',
        'saras_token_expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($user);

    $tokenManager = new SarasTokenManager;

    expect(fn () => $tokenManager->getAccessToken())
        ->toThrow(SarasApiException::class, 'Saras token expired');
});

test('invalidates user token', function () {
    $user = User::factory()->create([
        'saras_access_token' => 'token_to_invalidate',
        'saras_token_expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user);

    $tokenManager = new SarasTokenManager;
    $tokenManager->invalidateToken();

    $user->refresh();

    expect($user->saras_access_token)->toBeNull();
    expect($user->saras_token_expires_at)->toBeNull();
});
