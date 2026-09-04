<?php

use App\Models\User;
use App\Services\FaceAuth\SarasFaceAuthProvider;
use App\Services\FaceAuth\SarasFaceRegistrationService;
use App\Services\FaceAuth\SarasFaceRegistrationStatusService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

test('saras is the default face authentication provider', function () {
    expect(config('face_auth.provider'))->toBe('saras');
});

function sarasFaceAuthProvider(): SarasFaceAuthProvider
{
    return new SarasFaceAuthProvider(
        baseUrl: 'https://ind-prod.sarasfinance.com/v1',
        loginPath: '/users/loginWithFace',
        timeout: 5,
    );
}

test('saras face provider logs in with email and base64 selfie payload', function () {
    Http::fake([
        'https://ind-prod.sarasfinance.com/v1/users/loginWithFace' => Http::response([
            'success' => true,
            'access_token' => 'face-token',
            'expires_in' => 3600,
            'confidence' => 91,
        ]),
    ]);

    $result = sarasFaceAuthProvider()->verify(
        'lester@hurtado.ph',
        UploadedFile::fake()->image('selfie.jpg'),
        'txn-saras-face',
    );

    expect($result->verified)->toBeTrue()
        ->and($result->confidence)->toBe(91.0)
        ->and($result->details['access_token'])->toBe('face-token');

    Http::assertSent(fn ($request) => $request->url() === 'https://ind-prod.sarasfinance.com/v1/users/loginWithFace'
        && $request['client_id'] === 'lester@hurtado.ph'
        && is_string($request['face'])
        && $request['face'] !== '');
});

test('saras face provider returns not matched when login is rejected', function () {
    Http::fake([
        'https://ind-prod.sarasfinance.com/v1/users/loginWithFace' => Http::response([
            'success' => false,
            'message' => 'Face did not match.',
        ]),
    ]);

    $result = sarasFaceAuthProvider()->verify(
        'lester@hurtado.ph',
        UploadedFile::fake()->image('selfie.jpg'),
        'txn-saras-face-fail',
    );

    expect($result->verified)->toBeFalse()
        ->and($result->reason)->toBe('not_matched');
});

test('saras face provider exposes unauthorized api errors', function () {
    Http::fake([
        'https://ind-prod.sarasfinance.com/v1/users/loginWithFace' => Http::response([
            'error' => 'Unauthorized',
        ], 401),
    ]);

    $result = sarasFaceAuthProvider()->verify(
        'lester@hurtado.ph',
        UploadedFile::fake()->image('selfie.jpg'),
        'txn-saras-unauthorized',
    );

    expect($result->verified)->toBeFalse()
        ->and($result->reason)->toBe('error')
        ->and($result->details['message'])->toBe('Unauthorized')
        ->and($result->details['status'])->toBe(401);
});

test('saras face provider maps biometric registration error to not enrolled', function () {
    Http::fake([
        'https://ind-prod.sarasfinance.com/v1/users/loginWithFace' => Http::response([
            'errorCode' => 1509,
            'msg' => 'Face is not registered for biometric authentication.',
            'addMsg' => 'Face is not registered for user',
        ], 400),
    ]);

    $result = sarasFaceAuthProvider()->verify(
        'lester@hurtado.ph',
        UploadedFile::fake()->image('selfie.jpg'),
        'txn-saras-face-not-registered',
    );

    expect($result->verified)->toBeFalse()
        ->and($result->reason)->toBe('not_enrolled')
        ->and($result->details['message'])->toBe('Face is not registered for biometric authentication.')
        ->and($result->details['error_code'])->toBe(1509)
        ->and($result->details['registration_required'])->toBeTrue();
});

test('saras face provider exposes non json api errors', function () {
    Http::fake([
        'https://ind-prod.sarasfinance.com/v1/users/loginWithFace' => Http::response('Unauthorized', 401),
    ]);

    $result = sarasFaceAuthProvider()->verify(
        'lester@hurtado.ph',
        UploadedFile::fake()->image('selfie.jpg'),
        'txn-saras-plain-unauthorized',
    );

    expect($result->verified)->toBeFalse()
        ->and($result->details['message'])->toBe('Unauthorized')
        ->and($result->details['status'])->toBe(401);
});

test('saras face registration service sends selfie and document to saras', function () {
    $user = User::factory()->create([
        'saras_access_token' => 'temp-face-registration-token',
        'saras_token_expires_at' => now()->addMinutes(15),
    ]);

    Http::fake([
        'https://ind-prod.sarasfinance.com/v1/users/registerFaceForFaceAuthentication' => Http::response([
            'success' => true,
            'traceId' => 'trace-face-register',
        ]),
    ]);

    $service = new SarasFaceRegistrationService(
        baseUrl: 'https://ind-prod.sarasfinance.com/v1',
        registerPath: '/users/registerFaceForFaceAuthentication',
        timeout: 5,
    );

    $result = $service->register(
        $user,
        UploadedFile::fake()->image('selfie.jpg'),
        UploadedFile::fake()->image('document.jpg'),
    );

    expect($result['ok'])->toBeTrue()
        ->and($user->faceEnrollments()->where('provider', 'saras')->where('status', 'active')->exists())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://ind-prod.sarasfinance.com/v1/users/registerFaceForFaceAuthentication'
        && $request->hasHeader('Authorization', 'Bearer temp-face-registration-token')
        && is_string($request['image1'])
        && is_string($request['image2']));
});

test('saras face registration status service checks email status', function () {
    Http::fake([
        'https://ind-prod.sarasfinance.com/v1/users/checkSamlLoginEnabled' => Http::response([
            'samlLoginEnabled' => true,
        ]),
    ]);

    $service = new SarasFaceRegistrationStatusService(
        baseUrl: 'https://ind-prod.sarasfinance.com/v1',
        statusPath: '/users/checkSamlLoginEnabled',
        timeout: 5,
    );

    $result = $service->check('lester@hurtado.ph');

    expect($result['ok'])->toBeTrue()
        ->and($result['face_registration_enabled'])->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://ind-prod.sarasfinance.com/v1/users/checkSamlLoginEnabled'
        && $request['email'] === 'lester@hurtado.ph');
});

test('saras face registration status service detects required face enrollment', function () {
    Http::fake([
        'https://ind-prod.sarasfinance.com/v1/users/checkSamlLoginEnabled' => Http::response([
            'status' => false,
            'authStrategy' => 'FACE',
            'faceRegistered' => false,
            'branding' => [],
        ]),
    ]);

    $service = new SarasFaceRegistrationStatusService(
        baseUrl: 'https://ind-prod.sarasfinance.com/v1',
        statusPath: '/users/checkSamlLoginEnabled',
        timeout: 5,
    );

    $result = $service->check('lester@hurtado.ph');

    expect($result['ok'])->toBeTrue()
        ->and($result['auth_strategy'])->toBe('FACE')
        ->and($result['face_registered'])->toBeFalse()
        ->and($result['face_registration_required'])->toBeTrue();
});
