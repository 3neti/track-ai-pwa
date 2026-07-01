<?php

use App\Contracts\SarasClientInterface;
use App\Models\Contract;
use App\Models\User;
use App\Services\TrackAI\ContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('contracts page renders via inertia', function () {
    $this->withoutVite();

    Contract::factory()->count(2)->create();

    $response = $this->actingAs($this->user)
        ->get('/app/contracts');

    $response->assertSuccessful();
});

test('contracts API lists locally cached contracts', function () {
    Contract::factory()->count(3)->create();

    $response = $this->actingAs($this->user)
        ->getJson('/api/contracts');

    $response->assertSuccessful()
        ->assertJson(['success' => true])
        ->assertJsonCount(3, 'contracts');
});

test('contracts API returns contract fields correctly', function () {
    $contract = Contract::factory()->create([
        'name' => 'DPWH Bridge Project',
        'display_number' => '42',
        'milestones' => ['Foundation', 'Piers', 'Deck'],
        'certificate_status' => 'pending',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/contracts');

    $response->assertSuccessful();

    $first = $response->json('contracts.0');
    expect($first['name'])->toBe('DPWH Bridge Project');
    expect($first['display_number'])->toBe('42');
    expect($first['milestones'])->toBe(['Foundation', 'Piers', 'Deck']);
    expect($first['certificate_status'])->toBe('pending');
    expect($first)->toHaveKey('certificate_subproject_id');
});

test('contract refresh triggers sync from saras stub', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/contracts/refresh');

    // Stub returns empty processes, so contracts will be empty but call succeeds
    $response->assertSuccessful()
        ->assertJson(['success' => true]);
});

test('contract sync returns only contracts present in latest saras response', function () {
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

    $contracts = (new ContractService($client))->syncContractsFromSaras();

    expect($contracts)->toHaveCount(1)
        ->and($contracts->first()->saras_process_id)->toBe('current-contract-id')
        ->and($contracts->first()->name)->toBe('Current Contract');

    $this->assertDatabaseMissing('contracts', [
        'saras_process_id' => 'stale-contract-id',
    ]);
});

test('contract sync stores certificate source subproject from project progress', function () {
    config([
        'saras.subproject_ids.contract_ai' => 'contract-ai-subproject',
        'saras.subproject_ids.project_progress' => 'project-progress-subproject',
    ]);

    $client = Mockery::mock(SarasClientInterface::class);
    $client->shouldReceive('getProcesses')
        ->with('contract-ai-subproject', 1, 50)
        ->once()
        ->andReturn([
            'processes' => [[
                'id' => 'contract-process-id',
                'fields' => [
                    'legalName1' => 'Contract With Progress Certificate',
                    'milestone' => ['Foundation'],
                ],
                'metaDetails' => [
                    'displayNumber' => '1',
                ],
            ]],
        ]);
    $client->shouldReceive('getProcesses')
        ->with('project-progress-subproject', 1, 50)
        ->once()
        ->andReturn([
            'processes' => [[
                'fields' => [
                    'contractId' => 'contract-process-id',
                    'certificateOfCompletion' => 'progress-certificate-file-id',
                ],
            ]],
        ]);

    $contracts = (new ContractService($client))->syncContractsFromSaras();
    $contract = $contracts->first();

    expect($contract->certificate_status)->toBe(Contract::STATUS_AVAILABLE)
        ->and($contract->certificate_file_id)->toBe('progress-certificate-file-id')
        ->and($contract->certificate_subproject_id)->toBe('project-progress-subproject');
});

test('contract sync stores certificate source subproject from contract ai', function () {
    config([
        'saras.subproject_ids.contract_ai' => 'contract-ai-subproject',
        'saras.subproject_ids.project_progress' => 'project-progress-subproject',
    ]);

    $client = Mockery::mock(SarasClientInterface::class);
    $client->shouldReceive('getProcesses')
        ->with('contract-ai-subproject', 1, 50)
        ->once()
        ->andReturn([
            'processes' => [[
                'id' => 'contract-process-id',
                'fields' => [
                    'legalName1' => 'Contract With Contract AI Certificate',
                    'milestone' => ['Foundation'],
                    'certificateOfCompletion' => [
                        ['id' => 'contract-ai-certificate-file-id'],
                    ],
                ],
                'metaDetails' => [
                    'displayNumber' => '1',
                ],
            ]],
        ]);
    $client->shouldReceive('getProcesses')
        ->with('project-progress-subproject', 1, 50)
        ->once()
        ->andReturn(['processes' => []]);

    $contracts = (new ContractService($client))->syncContractsFromSaras();
    $contract = $contracts->first();

    expect($contract->certificate_status)->toBe(Contract::STATUS_AVAILABLE)
        ->and($contract->certificate_file_id)->toBe('contract-ai-certificate-file-id')
        ->and($contract->certificate_subproject_id)->toBe('contract-ai-subproject');
});

test('certificate endpoint returns 404 when not available', function () {
    $contract = Contract::factory()->create([
        'certificate_status' => 'not_started',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/contracts/{$contract->id}/certificate");

    $response->assertNotFound();
});

test('certificate endpoint returns download URL when certificate available', function () {
    $contract = Contract::factory()->available()->create();

    $response = $this->actingAs($this->user)
        ->getJson("/api/contracts/{$contract->id}/certificate");

    $response->assertSuccessful()
        ->assertJson(['success' => true])
        ->assertJsonStructure(['download_url']);
});

test('certificate endpoint opens file with stored certificate subproject', function () {
    $contract = Contract::factory()->available()->create([
        'certificate_file_id' => 'certificate-file-id',
        'certificate_subproject_id' => 'source-subproject-id',
        'certificate_url' => null,
        'raw_saras_payload' => [
            'fields' => [],
        ],
    ]);

    $client = Mockery::mock(SarasClientInterface::class);
    $client->shouldReceive('getFileUrl')
        ->with('source-subproject-id', 'certificate-file-id')
        ->once()
        ->andReturn([
            'urls' => [[
                'url' => 'https://storage.test/certificate-file-id',
            ]],
        ]);

    $this->app->instance(SarasClientInterface::class, $client);

    $response = $this->actingAs($this->user)
        ->getJson("/api/contracts/{$contract->id}/certificate");

    $response->assertSuccessful()
        ->assertJson([
            'success' => true,
            'download_url' => 'https://storage.test/certificate-file-id',
        ]);
});

test('certificate status resolves correctly for all states', function () {
    $notStarted = Contract::factory()->create();
    expect($notStarted->certificate_status)->toBe('not_started');
    expect($notStarted->isCertificateAvailable())->toBeFalse();

    $pending = Contract::factory()->pending()->create();
    expect($pending->certificate_status)->toBe('pending');
    expect($pending->isCertificateAvailable())->toBeFalse();

    $available = Contract::factory()->available()->create();
    expect($available->certificate_status)->toBe('available');
    expect($available->isCertificateAvailable())->toBeTrue();
    expect($available->certificate_file_id)->not->toBeNull();

    $unknown = Contract::factory()->unknown()->create();
    expect($unknown->certificate_status)->toBe('unknown');
});

test('contract factory creates valid records', function () {
    $contract = Contract::factory()->create();

    expect($contract->saras_process_id)->not->toBeNull();
    expect($contract->name)->not->toBeNull();
    expect($contract->milestones)->toBeArray();
    expect($contract->last_synced_at)->not->toBeNull();
});

test('unauthenticated requests to contracts are rejected', function () {
    $response = $this->getJson('/api/contracts');

    $response->assertUnauthorized();
});
