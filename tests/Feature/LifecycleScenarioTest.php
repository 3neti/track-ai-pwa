<?php

use App\Models\Project;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::create([
        'external_id' => 'LIFECYCLE-TEST-001',
        'name' => 'Lifecycle Test Project',
        'description' => 'Project for lifecycle tests',
        'status' => 'active',
    ]);
});

test('list scenarios via --list', function () {
    $this->artisan('trackai:lifecycle:run', ['--list' => true])
        ->expectsOutputToContain('basic_progress')
        ->expectsOutputToContain('full_lifecycle')
        ->assertExitCode(0);
});

test('run basic_progress scenario', function () {
    $this->artisan('trackai:lifecycle:run', [
        'scenario' => 'basic_progress',
        '--user' => $this->user->id,
        '--project' => $this->project->id,
    ])->assertExitCode(0);

    $this->assertDatabaseHas('project_progress_reports', [
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
        'current_milestone' => 'Foundation',
    ]);
});

test('run full_lifecycle scenario', function () {
    // Short timeout to avoid 300s wait in stub mode (stub IDs won't match poll IDs)
    $this->artisan('trackai:lifecycle:run', [
        'scenario' => 'full_lifecycle',
        '--user' => $this->user->id,
        '--project' => $this->project->id,
        '--timeout' => 2,
        '--poll' => 1,
    ])->assertSuccessful();

    $this->assertDatabaseHas('project_progress_reports', [
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
    ]);
});

test('unknown scenario returns failure', function () {
    $this->artisan('trackai:lifecycle:run', [
        'scenario' => 'nonexistent_scenario',
        '--user' => $this->user->id,
        '--project' => $this->project->id,
    ])->assertExitCode(1);
});

test('json output mode', function () {
    $this->artisan('trackai:lifecycle:run', [
        'scenario' => 'basic_progress',
        '--user' => $this->user->id,
        '--project' => $this->project->id,
        '--json' => true,
    ])->assertExitCode(0)
        ->expectsOutputToContain('"success": true');
});

test('missing scenario argument shows error', function () {
    $this->artisan('trackai:lifecycle:run')
        ->assertExitCode(1);
});

test('repository returns all scenarios', function () {
    $repo = app(App\Lifecycle\Scenarios\LifecycleScenarioRepository::class);

    $all = $repo->all();

    expect($all)->toHaveKeys(['basic_progress', 'full_lifecycle']);
    expect($all['basic_progress']['mode'])->toBe('default');
    expect($all['full_lifecycle']['mode'])->toBe('full_lifecycle');
});

test('repository findOrFail throws on unknown key', function () {
    $repo = app(App\Lifecycle\Scenarios\LifecycleScenarioRepository::class);

    expect(fn () => $repo->findOrFail('nonexistent'))
        ->toThrow(InvalidArgumentException::class);
});

test('repository byCategory filters correctly', function () {
    $repo = app(App\Lifecycle\Scenarios\LifecycleScenarioRepository::class);

    $smoke = $repo->byCategory('smoke');

    expect($smoke)->toHaveKeys(['basic_progress', 'full_lifecycle']);
    expect($repo->byCategory('nonexistent'))->toBeEmpty();
});

test('run dpwh_field_day scenario', function () {
    $this->artisan('trackai:lifecycle:run', [
        'scenario' => 'dpwh_field_day',
        '--user' => $this->user->id,
        '--project' => $this->project->id,
        '--timeout' => 2,
        '--poll' => 1,
    ])->assertExitCode(0);

    // Should have created attendance, uploads, and progress records
    $this->assertDatabaseHas('project_progress_reports', [
        'project_id' => $this->project->id,
        'user_id' => $this->user->id,
    ]);
});

test('dpwh_field_day appears in --list', function () {
    $this->artisan('trackai:lifecycle:run', ['--list' => true])
        ->expectsOutputToContain('dpwh_field_day')
        ->assertExitCode(0);
});

test('verbose mode shows API call summary', function () {
    $this->artisan('trackai:lifecycle:run', [
        'scenario' => 'basic_progress',
        '--user' => $this->user->id,
        '--project' => $this->project->id,
        '--trace' => true,
    ])->assertExitCode(0);
});

test('report mode shows flow diagram and artifacts', function () {
    $this->artisan('trackai:lifecycle:run', [
        'scenario' => 'dpwh_field_day',
        '--user' => $this->user->id,
        '--project' => $this->project->id,
        '--report' => true,
        '--timeout' => 2,
        '--poll' => 1,
    ])->assertExitCode(0)
        ->expectsOutputToContain('Lifecycle Flow')
        ->expectsOutputToContain('Run Artifacts')
        ->expectsOutputToContain('Saras Action Items');
});
