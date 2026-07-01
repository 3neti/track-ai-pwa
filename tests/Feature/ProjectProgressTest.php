<?php

use App\Contracts\SarasClientInterface;
use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectProgressReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::create([
        'external_id' => 'TEST-PROGRESS-001',
        'name' => 'Test Progress Project',
        'description' => 'A test project for progress reports',
        'status' => 'active',
    ]);
});

test('progress report can be created', function () {
    $response = $this->actingAs($this->user)
        ->postJson("/api/projects/{$this->project->id}/progress-reports", [
            'current_milestone' => 'Foundation',
            'remarks' => 'Concrete pouring completed for sector A.',
        ]);

    $response->assertSuccessful()
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('project_progress_reports', [
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'current_milestone' => 'Foundation',
        'remarks' => 'Concrete pouring completed for sector A.',
    ]);
});

test('progress reports can be listed for a project', function () {
    ProjectProgressReport::factory()->count(3)->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => $this->project->external_id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/projects/{$this->project->id}/progress-reports");

    $response->assertSuccessful()
        ->assertJson(['success' => true])
        ->assertJsonCount(3, 'data');
});

test('progress report stores file IDs as arrays', function () {
    $previousFiles = ['uuid-prev-1', 'uuid-prev-2'];
    $currentFiles = ['uuid-curr-1', 'uuid-curr-2', 'uuid-curr-3'];

    $response = $this->actingAs($this->user)
        ->postJson("/api/projects/{$this->project->id}/progress-reports", [
            'current_milestone' => 'Framing',
            'previous_progress_file_ids' => $previousFiles,
            'current_progress_file_ids' => $currentFiles,
        ]);

    $response->assertSuccessful();

    $report = ProjectProgressReport::latest()->first();
    expect($report->previous_progress_file_ids)->toBe($previousFiles);
    expect($report->current_progress_file_ids)->toBe($currentFiles);
});

test('workflow can be triggered for a submitted report', function () {
    $report = ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => $this->project->external_id,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/progress-reports/{$report->id}/workflow");

    $response->assertSuccessful();

    $report->refresh();
    expect($report->progress_status)->toBe('processing');
    expect($report->saras_workflow_run_id)->not->toBeNull();
});

test('workflow status can be polled', function () {
    $report = ProjectProgressReport::factory()->processing()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => $this->project->external_id,
        'saras_workflow_run_id' => 'run_stub_success_001',
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/progress-reports/{$report->id}/workflow");

    $response->assertSuccessful()
        ->assertJsonStructure(['success', 'report', 'workflow_run']);
});

test('automatically triggered workflow can be discovered by process ID', function () {
    $report = ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => $this->project->external_id,
        'saras_workflow_run_id' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/progress-reports/{$report->id}/workflow");

    $response->assertSuccessful()
        ->assertJsonPath('workflow_run.id', 'run_stub_success_001');

    $report->refresh();
    expect($report->saras_workflow_run_id)->toBe('run_stub_success_001')
        ->and($report->progress_status)->toBe(ProjectProgressReport::STATUS_EVALUATED);
});

test('validation rejects invalid remarks length', function () {
    $response = $this->actingAs($this->user)
        ->postJson("/api/projects/{$this->project->id}/progress-reports", [
            'remarks' => str_repeat('x', 2001),
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('remarks');
});

test('progress report cannot be created for an in progress milestone', function () {
    ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-locked',
        'current_milestone' => 'Foundation',
        'certificate_file_id' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/projects/{$this->project->id}/progress-reports", [
            'contract_id' => 'contract-locked',
            'current_milestone' => 'Foundation',
            'remarks' => 'Attempt to edit locked milestone.',
            'current_progress_file_ids' => ['new-file-id'],
        ]);

    $response->assertStatus(423)
        ->assertJson([
            'success' => false,
            'message' => 'This milestone is already in progress and cannot be edited.',
        ]);
});

test('progress report cannot be created when Saras has in progress milestone', function () {
    config(['saras.feature_flags.progress_enabled' => true]);

    $client = Mockery::mock(SarasClientInterface::class);
    $client->shouldReceive('getProcesses')
        ->with(config('saras.subproject_ids.project_progress'), 1, 50)
        ->once()
        ->andReturn([
            'processes' => [[
                'fields' => [
                    'contractId' => 'contract-remote-locked',
                    'currentMilestone' => 'Foundation',
                ],
            ]],
        ]);

    $this->app->instance(SarasClientInterface::class, $client);

    $response = $this->actingAs($this->user)
        ->postJson("/api/projects/{$this->project->id}/progress-reports", [
            'contract_id' => 'contract-remote-locked',
            'current_milestone' => 'Foundation',
            'remarks' => 'Attempt to edit remotely locked milestone.',
            'current_progress_file_ids' => ['new-file-id'],
        ]);

    $response->assertStatus(423)
        ->assertJson([
            'success' => false,
            'message' => 'This milestone is already in progress and cannot be edited.',
        ]);
});

test('progress report page renders via inertia', function () {
    $this->withoutVite();

    $response = $this->actingAs($this->user)
        ->get('/app/project-progress');

    $response->assertSuccessful();
});

test('progress page uses locally synchronized contracts and Saras identifiers', function () {
    $this->withoutVite();

    $contract = Contract::factory()->create([
        'saras_process_id' => 'saras-contract-123',
        'milestones' => ['Foundation', 'Roofing'],
    ]);

    $this->actingAs($this->user)
        ->get('/app/project-progress')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('app/ProjectProgress')
            ->where('contracts.0.id', 'saras-contract-123')
            ->where('contracts.0.local_id', $contract->id)
            ->where('contracts.0.saras_process_id', 'saras-contract-123')
            ->where('contracts.0.milestones', ['Foundation', 'Roofing'])
        );
});

test('progress page refreshes contracts and excludes stale local contract IDs', function () {
    $this->withoutVite();

    Contract::factory()->create([
        'saras_process_id' => 'stale-contract-id',
        'name' => 'Stale Contract',
    ]);

    $client = Mockery::mock(SarasClientInterface::class);
    $client->shouldReceive('isStubMode')
        ->once()
        ->andReturnFalse();
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

    $this->actingAs($this->user)
        ->get('/app/project-progress')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('app/ProjectProgress')
            ->has('contracts', 1)
            ->where('contracts.0.id', 'current-contract-id')
            ->where('contracts.0.saras_process_id', 'current-contract-id')
            ->where('contracts.0.name', 'Current Contract')
        );
});

test('unauthenticated requests are rejected', function () {
    $response = $this->postJson("/api/projects/{$this->project->id}/progress-reports", [
        'current_milestone' => 'Test',
    ]);

    $response->assertUnauthorized();
});

test('progress report factory states work correctly', function () {
    $draft = ProjectProgressReport::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
    ]);
    expect($draft->isDraft())->toBeTrue();

    $submitted = ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
    ]);
    expect($submitted->isSubmitted())->toBeTrue();
    expect($submitted->saras_process_id)->not->toBeNull();

    $evaluated = ProjectProgressReport::factory()->evaluated()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
    ]);
    expect($evaluated->isTerminal())->toBeTrue();
    expect($evaluated->completion_status)->toBe('SUCCESS');
});
