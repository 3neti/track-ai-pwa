<?php

use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('login page can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using username', function () {
    $user = User::factory()->create([
        'username' => 'testuser123',
        'password' => bcrypt('password'),
    ]);

    $response = $this->post('/login', [
        'username' => 'testuser123',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/app/contracts');
});

test('saras login refreshes contracts and removes stale cached contracts', function () {
    config([
        'saras.mode' => 'live',
        'saras.base_url' => 'https://saras.test/v1',
        'saras.subproject_ids.contract_ai' => 'contract-ai-subproject',
        'saras.subproject_ids.project_progress' => 'project-progress-subproject',
    ]);

    Contract::factory()->create([
        'saras_process_id' => 'stale-contract-id',
        'name' => 'Stale Contract',
    ]);

    Http::fake(function ($request) {
        $url = (string) $request->url();

        if (str_ends_with($url, '/users/userLogin')) {
            return Http::response([
                'access_token' => 'fresh-saras-token',
                'expires_in' => 3600,
            ]);
        }

        if (str_ends_with($url, '/users/getUserDetails')) {
            return Http::response([
                'id' => 'saras-user-id',
                'name' => 'Saras User',
                'tenantId' => [
                    'id' => 'tenant-id',
                    'name' => 'Tenant Name',
                ],
            ]);
        }

        if (str_contains($url, '/process/getProcess') && str_contains(urldecode($url), 'contract-ai-subproject')) {
            return Http::response([
                'processes' => [[
                    'id' => 'current-contract-id',
                    'fields' => [
                        'legalName1' => 'Current Contract',
                        'milestone' => ['Foundation Work'],
                    ],
                    'metaDetails' => [
                        'displayNumber' => '1',
                    ],
                ]],
            ]);
        }

        if (str_contains($url, '/process/getProcess') && str_contains(urldecode($url), 'project-progress-subproject')) {
            return Http::response(['processes' => []]);
        }

        return Http::response([], 404);
    });

    $response = $this->post('/login', [
        'username' => 'lester@example.test',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/app/contracts');

    $this->assertDatabaseHas('contracts', [
        'saras_process_id' => 'current-contract-id',
        'name' => 'Current Contract',
    ]);

    $this->assertDatabaseMissing('contracts', [
        'saras_process_id' => 'stale-contract-id',
    ]);
});

test('saras login with face registration token redirects to face registration', function () {
    config([
        'saras.mode' => 'live',
        'saras.base_url' => 'https://saras.test/v1',
    ]);

    Http::fake([
        'https://saras.test/v1/users/userLogin' => Http::response([
            'access_token' => 'temporary-face-registration-token',
            'expires_in' => 900,
            'face_registration_required' => true,
        ]),
    ]);

    $response = $this->post('/login', [
        'username' => 'lester@example.test',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('face-register'));
    $this->assertDatabaseHas('users', [
        'email' => 'lester@example.test',
        'saras_access_token' => null,
    ]);
    $response->assertSessionHas('saras_face_registration_token', 'temporary-face-registration-token');
});

test('saras login redirects to face registration when face auth profile is not registered', function () {
    config([
        'saras.mode' => 'live',
        'saras.base_url' => 'https://saras.test/v1',
    ]);

    Http::fake([
        'https://saras.test/v1/users/userLogin' => Http::response([
            'access_token' => 'temporary-face-registration-token',
            'expires_in' => 900,
        ]),
        'https://saras.test/v1/users/checkSamlLoginEnabled' => Http::response([
            'status' => false,
            'authStrategy' => 'FACE',
            'faceRegistered' => false,
        ]),
    ]);

    $response = $this->post('/login', [
        'username' => 'lester@example.test',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('face-register'));
    $this->assertDatabaseHas('users', [
        'email' => 'lester@example.test',
        'saras_access_token' => null,
    ]);
    $response->assertSessionHas('saras_face_registration_token', 'temporary-face-registration-token');
});

test('face registration status endpoint exposes required enrollment state', function () {
    Http::fake([
        '*/users/checkSamlLoginEnabled' => Http::response([
            'status' => false,
            'authStrategy' => 'FACE',
            'faceRegistered' => false,
            'branding' => [],
        ]),
    ]);

    $response = $this->postJson('/auth/face/registration-status', [
        'email' => 'lester@example.test',
    ]);

    $response->assertOk()
        ->assertJson([
            'ok' => true,
            'auth_strategy' => 'FACE',
            'face_registered' => false,
            'face_registration_required' => true,
        ]);
});

test('live saras login outage does not authenticate with expired stored token', function () {
    config([
        'saras.mode' => 'live',
        'saras.base_url' => 'https://saras.test/v1',
    ]);

    User::factory()->create([
        'email' => 'lester@example.test',
        'username' => 'lester@example.test',
        'password' => bcrypt('password'),
        'saras_access_token' => 'expired-saras-token',
        'saras_token_expires_at' => now()->subMinute(),
    ]);

    Http::fake(function () {
        throw new ConnectionException('Connection timed out');
    });

    $response = $this->from('/login')->post('/login', [
        'username' => 'lester@example.test',
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect('/login')
        ->assertSessionHasErrors([
            'username' => 'Saras login is currently unavailable, and the stored Saras token is expired. Please try again when Saras auth is reachable.',
        ]);
});

test('live saras login outage reports missing stored token instead of invalid credentials', function () {
    config([
        'saras.mode' => 'live',
        'saras.base_url' => 'https://saras.test/v1',
    ]);

    User::factory()->create([
        'email' => 'lester@example.test',
        'username' => 'lester@example.test',
        'password' => bcrypt('password'),
        'saras_access_token' => null,
        'saras_token_expires_at' => null,
    ]);

    Http::fake(function () {
        throw new ConnectionException('Connection timed out');
    });

    $response = $this->from('/login')->post('/login', [
        'username' => 'lester@example.test',
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect('/login')
        ->assertSessionHasErrors([
            'username' => 'Saras login is currently unavailable, and this local account has no stored Saras token. Please try again when Saras auth is reachable.',
        ]);
});

test('live saras login outage can authenticate locally with valid stored token', function () {
    config([
        'saras.mode' => 'live',
        'saras.base_url' => 'https://saras.test/v1',
    ]);

    User::factory()->create([
        'email' => 'lester@example.test',
        'username' => 'lester@example.test',
        'password' => bcrypt('password'),
        'saras_access_token' => 'valid-saras-token',
        'saras_token_expires_at' => now()->addMinutes(30),
    ]);

    Http::fake(function () {
        throw new ConnectionException('Connection timed out');
    });

    $response = $this->post('/login', [
        'username' => 'lester@example.test',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/app/contracts');
});

test('users cannot authenticate with invalid username', function () {
    $user = User::factory()->create([
        'username' => 'testuser123',
        'password' => bcrypt('password'),
    ]);

    $this->post('/login', [
        'username' => 'wronguser',
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('users cannot authenticate with invalid password', function () {
    $user = User::factory()->create([
        'username' => 'testuser123',
        'password' => bcrypt('password'),
    ]);

    $this->post('/login', [
        'username' => 'testuser123',
        'password' => 'wrongpassword',
    ]);

    $this->assertGuest();
});
