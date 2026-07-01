<?php

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

test('second report auto-populates previous files from first report current files', function () {
    // Create first report (submitted status so it qualifies)
    ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-abc',
        'current_milestone' => 'Foundation',
        'current_progress_file_ids' => ['uuid-r1-curr-1', 'uuid-r1-curr-2', 'uuid-r1-curr-3'],
    ]);

    // Create second report without previous_progress_file_ids
    $report2 = $this->service->createProgress(
        user: $this->user,
        project: $this->project,
        input: [
            'contract_id' => 'contract-abc',
            'current_milestone' => 'Foundation',
            'remarks' => 'Second report',
            'current_progress_file_ids' => ['uuid-r2-curr-1'],
        ],
    );

    // Previous should be auto-populated from report 1's current files
    expect($report2->previous_progress_file_ids)->toBe(['uuid-r1-curr-1', 'uuid-r1-curr-2', 'uuid-r1-curr-3']);
    expect($report2->current_progress_file_ids)->toBe(['uuid-r2-curr-1']);
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

    $fileIds = $this->service->resolvePreviousProgressFileIds('contract-abc', 'Roofing');

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

    $fileIds = $this->service->resolvePreviousProgressFileIds('contract-abc', 'Foundation');

    expect($fileIds)->toBe([]);
});

test('resolvePreviousProgressFileIds includes failed reports (files are still valid)', function () {
    ProjectProgressReport::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-abc',
        'current_milestone' => 'Foundation',
        'progress_status' => 'failed',
        'current_progress_file_ids' => ['uuid-failed-1'],
    ]);

    $fileIds = $this->service->resolvePreviousProgressFileIds('contract-abc', 'Foundation');

    expect($fileIds)->toBe(['uuid-failed-1']);
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

    $fileIds = $this->service->resolvePreviousProgressFileIds('contract-abc', 'Foundation');

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
    ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-xyz',
        'current_milestone' => 'Floor1',
        'current_progress_file_ids' => ['uuid-a', 'uuid-b'],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/contracts/contract-xyz/milestones/Floor1/previous-progress');

    $response->assertSuccessful()
        ->assertJson([
            'success' => true,
            'isFirstReport' => false,
            'previousFileCount' => 2,
        ]);
});

test('explicitly provided previous file IDs are not overridden', function () {
    // Create an existing submitted report
    ProjectProgressReport::factory()->submitted()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'contract_id' => 'contract-abc',
        'current_milestone' => 'Foundation',
        'current_progress_file_ids' => ['uuid-auto-1', 'uuid-auto-2'],
    ]);

    // Submit with explicit previous file IDs
    $report = $this->service->createProgress(
        user: $this->user,
        project: $this->project,
        input: [
            'contract_id' => 'contract-abc',
            'current_milestone' => 'Foundation',
            'remarks' => 'Explicit previous',
            'previous_progress_file_ids' => ['uuid-explicit-1'],
            'current_progress_file_ids' => ['uuid-curr-1'],
        ],
    );

    // Should use explicit IDs, not auto-resolved
    expect($report->previous_progress_file_ids)->toBe(['uuid-explicit-1']);
});
