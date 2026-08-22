<?php

use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectProgressReport;
use App\Models\User;
use App\Services\TrackAI\ProjectProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::create([
        'external_id' => 'TEST-AUTOPOP-001',
        'name' => 'Auto-Populate Test Project',
        'description' => 'Test project for auto-populate previous progress',
        'status' => 'active',
    ]);
    Contract::factory()->create([
        'saras_process_id' => 'contract-abc',
        'name' => 'Contract ABC',
        'milestones' => ['Foundation', 'Floor1', 'Floor2', 'Roofing'],
    ]);
    $this->service = app(ProjectProgressService::class);
});

test('first report has empty previous progress files', function () {
    $report = $this->service->createProgress(
        user: $this->user,
        project: $this->project,
        input: [
            'contract_id' => 'contract-abc',
            'current_milestone' => 'Foundation',
            'remarks' => 'First report',
            'current_progress_file_ids' => ['uuid-curr-1', 'uuid-curr-2'],
        ],
    );

    expect($report->previous_progress_file_ids)->toBe([]);
    expect($report->current_progress_file_ids)->toBe(['uuid-curr-1', 'uuid-curr-2']);
});

test('next milestone auto-populates previous files from previous milestone current files', function () {
    ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-abc',
        'current_milestone' => 'Foundation',
        'current_progress_file_ids' => ['uuid-r1-curr-1', 'uuid-r1-curr-2', 'uuid-r1-curr-3'],
    ]);

    $report2 = $this->service->createProgress(
        user: $this->user,
        project: $this->project,
        input: [
            'contract_id' => 'contract-abc',
            'current_milestone' => 'Floor1',
            'remarks' => 'Floor1 report',
            'current_progress_file_ids' => ['uuid-r2-curr-1'],
        ],
    );

    expect($report2->previous_progress_file_ids)->toBe(['uuid-r1-curr-1', 'uuid-r1-curr-2', 'uuid-r1-curr-3']);
    expect($report2->current_progress_file_ids)->toBe(['uuid-r2-curr-1']);
});

test('repeat report for same milestone does not use same milestone files as previous files', function () {
    ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-abc',
        'current_milestone' => 'Foundation',
        'current_progress_file_ids' => ['uuid-foundation-current'],
    ]);

    ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-abc',
        'current_milestone' => 'Floor1',
        'current_progress_file_ids' => ['uuid-floor1-first-current'],
    ]);

    $report = $this->service->createProgress(
        user: $this->user,
        project: $this->project,
        input: [
            'contract_id' => 'contract-abc',
            'current_milestone' => 'Floor1',
            'remarks' => 'Second Floor1 report still compares against Foundation.',
            'current_progress_file_ids' => ['uuid-floor1-second-current'],
        ],
    );

    expect($report->previous_progress_file_ids)->toBe(['uuid-foundation-current']);
});

test('resolvePreviousProgressFileIds returns empty for unknown contract', function () {
    $fileIds = $this->service->resolvePreviousProgressFileIds('nonexistent-contract', 'Foundation');

    expect($fileIds)->toBe([]);
});

test('resolvePreviousProgressFileIds returns empty for unknown milestone', function () {
    ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-abc',
        'current_milestone' => 'Foundation',
        'current_progress_file_ids' => ['uuid-1'],
    ]);

    $fileIds = $this->service->resolvePreviousProgressFileIds('contract-abc', 'Unknown');

    expect($fileIds)->toBe([]);
});

test('resolvePreviousProgressFileIds skips draft reports', function () {
    ProjectProgressReport::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-abc',
        'current_milestone' => 'Foundation',
        'progress_status' => 'draft',
        'current_progress_file_ids' => ['uuid-draft-1'],
    ]);

    $fileIds = $this->service->resolvePreviousProgressFileIds('contract-abc', 'Floor1');

    expect($fileIds)->toBe([]);
});

test('resolvePreviousProgressFileIds skips failed reports', function () {
    ProjectProgressReport::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-abc',
        'current_milestone' => 'Foundation',
        'progress_status' => 'failed',
        'current_progress_file_ids' => ['uuid-failed-1'],
    ]);

    $fileIds = $this->service->resolvePreviousProgressFileIds('contract-abc', 'Floor1');

    expect($fileIds)->toBe([]);
});

test('resolvePreviousProgressFileIds skips newer reports without current files', function () {
    ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-abc',
        'current_milestone' => 'Foundation',
        'current_progress_file_ids' => ['uuid-previous-1', 'uuid-previous-2'],
        'created_at' => now()->subMinute(),
    ]);

    ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-abc',
        'current_milestone' => 'Foundation',
        'current_progress_file_ids' => [],
        'created_at' => now(),
    ]);

    $fileIds = $this->service->resolvePreviousProgressFileIds('contract-abc', 'Floor1');

    expect($fileIds)->toBe(['uuid-previous-1', 'uuid-previous-2']);
});

test('previous progress endpoint returns first report status', function () {
    $response = $this->actingAs($this->user)
        ->getJson('/api/contracts/contract-abc/milestones/Foundation/previous-progress');

    $response->assertSuccessful()
        ->assertJson([
            'success' => true,
            'isFirstReport' => true,
            'previousFileCount' => 0,
        ]);
});

test('previous progress endpoint returns file count from last report', function () {
    Contract::factory()->create([
        'saras_process_id' => 'contract-xyz',
        'name' => 'Contract XYZ',
        'milestones' => ['Floor1', 'Floor2'],
    ]);

    ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-xyz',
        'current_milestone' => 'Floor1',
        'current_progress_file_ids' => ['uuid-a', 'uuid-b'],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/contracts/contract-xyz/milestones/Floor2/previous-progress');

    $response->assertSuccessful()
        ->assertJson([
            'success' => true,
            'isFirstReport' => false,
            'previousFileCount' => 2,
        ]);
});

test('explicitly provided previous file IDs are ignored in favor of previous milestone auto resolution', function () {
    ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-abc',
        'current_milestone' => 'Foundation',
        'current_progress_file_ids' => ['uuid-auto-1', 'uuid-auto-2'],
    ]);

    $report = $this->service->createProgress(
        user: $this->user,
        project: $this->project,
        input: [
            'contract_id' => 'contract-abc',
            'current_milestone' => 'Floor1',
            'remarks' => 'Explicit previous should be ignored by backend auto resolution.',
            'previous_progress_file_ids' => ['uuid-explicit-1'],
            'current_progress_file_ids' => ['uuid-curr-1'],
        ],
    );

    expect($report->previous_progress_file_ids)->toBe(['uuid-auto-1', 'uuid-auto-2']);
});
