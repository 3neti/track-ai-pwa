<?php

use App\Models\Contract;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

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
});

test('contract refresh triggers sync from saras stub', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/contracts/refresh');

    // Stub returns empty processes, so contracts will be empty but call succeeds
    $response->assertSuccessful()
        ->assertJson(['success' => true]);
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
