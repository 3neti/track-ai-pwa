<?php

use App\Models\Project;
use App\Models\ProjectProgressReport;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

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

test('validation rejects invalid remarks length', function () {
    $response = $this->actingAs($this->user)
        ->postJson("/api/projects/{$this->project->id}/progress-reports", [
            'remarks' => str_repeat('x', 2001),
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('remarks');
});

test('progress report page renders via inertia', function () {
    $this->withoutVite();

    $response = $this->actingAs($this->user)
        ->get('/app/project-progress');

    $response->assertSuccessful();
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
