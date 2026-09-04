<?php

use App\Models\FaceEnrollment;
use App\Models\User;
use App\Services\FaceAuth\HypervergeDirectProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function hypervergeProvider(float $threshold = 85): HypervergeDirectProvider
{
    return new HypervergeDirectProvider(
        baseUrl: 'https://sgp.idv.hyperverge.co/v1',
        appId: 'test-app-id',
        appKey: 'test-app-key',
        livenessPath: '/checkLiveness',
        matchPath: '/matchFace',
        matchType: 'face_face',
        confidenceThreshold: $threshold,
        timeout: 5,
    );
}

function enrollFaceReference(User $user): void
{
    Storage::fake('local');
    Storage::disk('local')->put("face-enrollments/{$user->id}/reference.jpeg", 'reference-image');

    FaceEnrollment::create([
        'user_id' => $user->id,
        'provider' => 'hyperverge',
        'disk' => 'local',
        'path' => "face-enrollments/{$user->id}/reference.jpeg",
        'status' => 'active',
        'enrolled_at' => now(),
    ]);
}

function livenessPassResponse(): array
{
    return [
        'result' => [
            'details' => [
                'liveFace' => ['value' => 'yes'],
            ],
            'summary' => ['action' => 'pass'],
        ],
    ];
}

function matchResponse(string $value = 'yes', string $confidence = 'very_high', string $action = 'pass'): array
{
    return [
        'result' => [
            'details' => [
                'match' => [
                    'value' => $value,
                    'confidence' => $confidence,
                ],
            ],
            'summary' => ['action' => $action],
        ],
    ];
}

test('direct provider verifies when liveness and face match pass', function () {
    $user = User::factory()->create(['username' => 'faceuser']);
    enrollFaceReference($user);

    Http::fakeSequence()
        ->push(livenessPassResponse())
        ->push(matchResponse());

    $result = hypervergeProvider()->verify(
        'faceuser',
        UploadedFile::fake()->image('selfie.jpg'),
        'txn-001',
    );

    expect($result->verified)->toBeTrue()
        ->and($result->confidence)->toBe(95.0);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkLiveness'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/matchFace'));
});

test('direct provider returns not enrolled when no reference image exists', function () {
    User::factory()->create(['username' => 'not_enrolled']);

    Http::fake();

    $result = hypervergeProvider()->verify(
        'not_enrolled',
        UploadedFile::fake()->image('selfie.jpg'),
        'txn-002',
    );

    expect($result->verified)->toBeFalse()
        ->and($result->reason)->toBe('not_enrolled');

    Http::assertNothingSent();
});

test('direct provider stops when liveness fails', function () {
    $user = User::factory()->create(['username' => 'quality_user']);
    enrollFaceReference($user);

    Http::fakeSequence()->push([
        'result' => [
            'details' => [
                'liveFace' => ['value' => 'no'],
                'qualityChecks' => [
                    'blur' => ['value' => 'yes'],
                ],
            ],
            'summary' => [
                'action' => 'fail',
                'details' => [
                    ['message' => 'Face is blurred'],
                ],
            ],
        ],
    ]);

    $result = hypervergeProvider()->verify(
        'quality_user',
        UploadedFile::fake()->image('selfie.jpg'),
        'txn-003',
    );

    expect($result->verified)->toBeFalse()
        ->and($result->reason)->toBe('quality')
        ->and($result->details['issue'])->toContain('Face is blurred');

    Http::assertSentCount(1);
});

test('direct provider rejects low confidence matches', function () {
    $user = User::factory()->create(['username' => 'low_confidence']);
    enrollFaceReference($user);

    Http::fakeSequence()
        ->push(livenessPassResponse())
        ->push(matchResponse(confidence: 'medium'));

    $result = hypervergeProvider()->verify(
        'low_confidence',
        UploadedFile::fake()->image('selfie.jpg'),
        'txn-004',
    );

    expect($result->verified)->toBeFalse()
        ->and($result->reason)->toBe('not_matched')
        ->and($result->confidence)->toBe(60.0);
});

test('direct provider handles hyperverge api errors', function () {
    $user = User::factory()->create(['username' => 'api_error']);
    enrollFaceReference($user);

    Http::fakeSequence()->push(['message' => 'Service unavailable'], 500);

    $result = hypervergeProvider()->verify(
        'api_error',
        UploadedFile::fake()->image('selfie.jpg'),
        'txn-005',
    );

    expect($result->verified)->toBeFalse()
        ->and($result->reason)->toBe('error');
});
