<?php

use App\Contracts\SarasClientInterface;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('contract sync command purges cached contracts', function () {
    Contract::factory()->count(3)->create();

    $this->artisan('trackai:contracts:sync --purge --force')
        ->assertSuccessful();

    expect(Contract::count())->toBe(0);
});

test('contract sync command refreshes and prunes contracts using a user token', function () {
    $user = User::factory()->create([
        'email' => 'lester@example.test',
        'username' => 'lester',
        'saras_access_token' => 'token',
        'saras_token_expires_at' => now()->addHour(),
    ]);

    Contract::factory()->create([
        'saras_process_id' => 'stale-contract-id',
        'name' => 'Stale Contract',
    ]);

    $client = Mockery::mock(SarasClientInterface::class);
    $client->shouldReceive('getProcesses')
        ->with(config('saras.subproject_ids.contract_ai'), 1, 50)
        ->once()
        ->andReturn([
            'processes' => [[
                'id' => 'current-contract-id',
                'fields' => [
                    'legalName1' => 'Current Contract',
                    'milestone' => ['Foundation'],
                ],
                'metaDetails' => [
                    'displayNumber' => '1',
                ],
            ]],
        ]);
    $client->shouldReceive('getProcesses')
        ->with(config('saras.subproject_ids.project_progress'), 1, 50)
        ->once()
        ->andReturn(['processes' => []]);

    $this->app->instance(SarasClientInterface::class, $client);

    $this->artisan("trackai:contracts:sync --user={$user->email}")
        ->assertSuccessful();

    $this->assertDatabaseHas('contracts', [
        'saras_process_id' => 'current-contract-id',
        'name' => 'Current Contract',
    ]);

    $this->assertDatabaseMissing('contracts', [
        'saras_process_id' => 'stale-contract-id',
    ]);
});

test('contract sync command fails without a user token', function () {
    $this->artisan('trackai:contracts:sync')
        ->assertFailed();
});
