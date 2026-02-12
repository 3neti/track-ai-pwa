<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;

test('face login page can be rendered', function () {
    $response = $this->get('/face-login?username=testuser');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('auth/FaceLogin')
        ->has('username')
    );
});

test('face login page requires username parameter', function () {
    $response = $this->get('/face-login');

    $response->assertRedirect(route('login'));
});

test('successful face verification logs in the user', function () {
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

test('face verification fails for non-matching face', function () {
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
