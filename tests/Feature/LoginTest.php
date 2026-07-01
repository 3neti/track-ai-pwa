<?php

use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
