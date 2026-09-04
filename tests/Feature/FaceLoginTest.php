<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

test('face login page can be rendered', function () {
    $response = $this->get('/face-login?username=testuser');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('auth/FaceLogin')
        ->has('username')
    );
});

test('login page preserves username query for face registration handoff', function () {
    $response = $this->get('/login?username=lester%40hurtado.ph');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('auth/Login')
        ->where('initialUsername', 'lester@hurtado.ph')
    );
});

test('face login page requires username parameter', function () {
    $response = $this->get('/face-login');

    $response->assertRedirect(route('login'));
});

test('face registration page requires authentication', function () {
    $response = $this->get('/face-register?username=lester%40hurtado.ph');

    $response->assertRedirect(route('login'));
});

test('face registration page can be rendered for authenticated user', function () {
    $user = User::factory()->create([
        'email' => 'lester@hurtado.ph',
    ]);

    $response = $this->actingAs($user)->get('/face-register');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('auth/FaceRegister')
        ->where('username', 'lester@hurtado.ph')
    );
});

test('face registration submit uses temporary session token and registers images with saras', function () {
    config([
        'saras.mode' => 'live',
        'saras.base_url' => 'https://saras.test/v1',
    ]);

    $user = User::factory()->create([
        'email' => 'lester@hurtado.ph',
        'username' => 'lester@hurtado.ph',
        'saras_access_token' => null,
    ]);

    Http::fake([
        'https://saras.test/v1/users/registerFaceForFaceAuthentication' => Http::response([
            'success' => true,
            'traceId' => 'trace-face-register',
        ]),
    ]);

    $response = $this
        ->actingAs($user)
        ->withSession(['saras_face_registration_token' => 'temporary-face-registration-token'])
        ->post('/auth/face/register', [
            'selfie' => UploadedFile::fake()->image('selfie.jpg', 640, 480),
            'document' => UploadedFile::fake()->image('document.jpg', 640, 480),
        ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'redirect' => route('face-login', ['username' => 'lester@hurtado.ph']),
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'lester@hurtado.ph',
        'saras_access_token' => null,
    ]);

    $this->assertDatabaseHas('face_enrollments', [
        'provider' => 'saras',
        'status' => 'active',
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://saras.test/v1/users/registerFaceForFaceAuthentication'
        && $request->hasHeader('Authorization', 'Bearer temporary-face-registration-token')
        && is_string($request['image1'])
        && is_string($request['image2']));
});

test('successful face verification logs in the user', function () {
    config(['face_auth.provider' => 'stub']);

    $user = User::factory()->create([
        'username' => 'faceuser',
    ]);

    $selfie = UploadedFile::fake()->image('selfie.jpg', 640, 480);

    $response = $this->postJson('/auth/face/verify', [
        'username' => 'faceuser',
        'selfie' => $selfie,
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['verified' => true]);
    $response->assertJsonFragment(['ok' => true]);
    expect($response->json('redirect'))->toContain('/app/projects');
    $this->assertAuthenticatedAs($user);
});

test('successful saras face verification stores returned saras token', function () {
    config(['face_auth.provider' => 'saras']);

    $user = User::factory()->create([
        'username' => 'lester@hurtado.ph',
        'email' => 'lester@hurtado.ph',
        'saras_access_token' => null,
        'saras_token_expires_at' => null,
    ]);

    Http::fake([
        '*/users/loginWithFace' => Http::response([
            'success' => true,
            'access_token' => 'saras-face-token',
            'expires_in' => 3600,
        ]),
    ]);

    $response = $this->postJson('/auth/face/verify', [
        'username' => 'lester@hurtado.ph',
        'selfie' => UploadedFile::fake()->image('selfie.jpg', 640, 480),
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['verified' => true]);
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->saras_access_token)->toBe('saras-face-token');
});

test('saras face verification returns registration handoff when face is not registered', function () {
    config(['face_auth.provider' => 'saras']);

    User::factory()->create([
        'username' => 'lester@hurtado.ph',
        'email' => 'lester@hurtado.ph',
    ]);

    Http::fake([
        '*/users/loginWithFace' => Http::response([
            'errorCode' => 1509,
            'msg' => 'Face is not registered for biometric authentication.',
            'addMsg' => 'Face is not registered for user',
        ], 400),
    ]);

    $response = $this->postJson('/auth/face/verify', [
        'username' => 'lester@hurtado.ph',
        'selfie' => UploadedFile::fake()->image('selfie.jpg', 640, 480),
    ]);

    $response->assertOk();
    $response->assertJson([
        'verified' => false,
        'reason' => 'not_enrolled',
        'details' => [
            'message' => 'Face is not registered for biometric authentication.',
            'registration_required' => true,
        ],
    ]);
    expect($response->json('details.registration_url'))->toBe(route('login', ['username' => 'lester@hurtado.ph']));
    $this->assertGuest();
});

test('face verification fails for non-matching face', function () {
    config(['face_auth.provider' => 'stub']);

    User::factory()->create([
        'username' => 'fail_match',
    ]);

    $selfie = UploadedFile::fake()->image('selfie.jpg', 640, 480);

    $response = $this->postJson('/auth/face/verify', [
        'username' => 'fail_match',
        'selfie' => $selfie,
    ]);

    $response->assertOk();
    $response->assertJson([
        'verified' => false,
        'reason' => 'not_matched',
    ]);
    $this->assertGuest();
});

test('face verification returns quality failure details', function () {
    config(['face_auth.provider' => 'stub']);

    User::factory()->create([
        'username' => 'fail_quality',
    ]);

    $selfie = UploadedFile::fake()->image('selfie.jpg', 640, 480);

    $response = $this->postJson('/auth/face/verify', [
        'username' => 'fail_quality',
        'selfie' => $selfie,
    ]);

    $response->assertOk();
    $response->assertJson([
        'verified' => false,
        'reason' => 'quality',
    ]);
    $response->assertJsonStructure([
        'verified',
        'reason',
        'details' => ['issue'],
    ]);
    $this->assertGuest();
});

test('face verification handles provider errors gracefully', function () {
    User::factory()->create([
        'username' => 'fail_error',
    ]);

    $selfie = UploadedFile::fake()->image('selfie.jpg', 640, 480);

    $response = $this->postJson('/auth/face/verify', [
        'username' => 'fail_error',
        'selfie' => $selfie,
    ]);

    $response->assertOk();
    $response->assertJson([
        'verified' => false,
        'reason' => 'error',
    ]);
    $this->assertGuest();
});

test('face verification for non-existent user does not leak user existence', function () {
    $selfie = UploadedFile::fake()->image('selfie.jpg', 640, 480);

    $response = $this->postJson('/auth/face/verify', [
        'username' => 'nonexistent_user_xyz',
        'selfie' => $selfie,
    ]);

    // Should return same response as a failed match, not reveal user doesn't exist
    $response->assertOk();
    $response->assertJson([
        'verified' => false,
    ]);
    $this->assertGuest();
});

test('face verification validates required fields', function () {
    $response = $this->postJson('/auth/face/verify', []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['username', 'selfie']);
});

test('face verification validates selfie is an image', function () {
    $notAnImage = UploadedFile::fake()->create('document.pdf', 100);

    $response = $this->postJson('/auth/face/verify', [
        'username' => 'testuser',
        'selfie' => $notAnImage,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['selfie']);
});

test('face verification validates selfie size limit', function () {
    // Create a file larger than 5MB
    $largeSelfie = UploadedFile::fake()->image('selfie.jpg')->size(6000);

    $response = $this->postJson('/auth/face/verify', [
        'username' => 'testuser',
        'selfie' => $largeSelfie,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['selfie']);
});

test('face login is rate limited', function () {
    User::factory()->create([
        'username' => 'ratelimitedface',
    ]);

    // Make 10 requests to exhaust the rate limiter
    for ($i = 0; $i < 10; $i++) {
        $selfie = UploadedFile::fake()->image('selfie.jpg', 640, 480);
        $this->postJson('/auth/face/verify', [
            'username' => 'ratelimitedface',
            'selfie' => $selfie,
        ]);
    }

    // 11th request should be rate limited
    $selfie = UploadedFile::fake()->image('selfie.jpg', 640, 480);

    $response = $this->postJson('/auth/face/verify', [
        'username' => 'ratelimitedface',
        'selfie' => $selfie,
    ]);

    $response->assertTooManyRequests();
});
