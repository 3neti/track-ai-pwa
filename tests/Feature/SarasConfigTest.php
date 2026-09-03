<?php

use App\Contracts\SarasClientInterface;
use App\Http\Responses\LoginResponse;
use App\Models\User;
use App\Services\Branding\BrandingResolver;
use App\Services\Saras\DTO\ProjectsResponse;
use App\Services\Saras\SarasProjectContextResolver;
use Illuminate\Http\Request;

test('saras login credentials are exposed through configuration', function () {
    $sarasConfig = config('saras');

    expect($sarasConfig)->toHaveKey('username')
        ->and($sarasConfig)->toHaveKey('password');
});

test('saras payload map exposes joint confirmation operations', function () {
    $payloadMap = config('saras.payload_map');

    expect($payloadMap)->toHaveKeys([
        'userLogin',
        'getUserDetails',
        'getProjectsForUser',
        'getProcess',
        'createProcess',
        'uploadFiles',
        'updateFiles',
        'executeWorkflow',
        'getWorkflowRuns',
    ])
        ->and($payloadMap['createProcess']['request_shape'])->toHaveKey('subProjectId')
        ->and($payloadMap['executeWorkflow']['config_keys'])->toContain('saras.workflows.completion_id');
});

test('authenticated developers can retrieve the Saras payload map', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/developer/api/payload-map')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.createProcess.endpoint', '/process/createProcess');
});

test('login page exposes configured project id as the default context', function () {
    config(['saras.project_id' => 'configured-project-id']);

    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/Login')
            ->where('defaultProjectId', 'configured-project-id')
        );
});

test('developer Saras API X-Ray page receives branding support', function () {
    config([
        'branding.name' => 'DPWH Demo',
        'branding.short_name' => 'DPWH',
        'branding.square_logo' => '/branding/square.png',
        'branding.rectangle_logo' => '/branding/rectangle.png',
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/developer/saras-api-xray')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('developer/SarasApiXray')
            ->where('branding.name', 'DPWH Demo')
            ->where('branding.short_name', 'DPWH')
            ->where('branding.square_logo', '/branding/square.png')
            ->where('branding.rectangle_logo', '/branding/rectangle.png')
        );
});

test('saved project id overrides the configured Saras project context', function () {
    config([
        'saras.mode' => 'live',
        'saras.project_id' => 'configured-project-id',
        'branding.remote.enabled' => true,
    ]);

    $user = User::factory()->create(['selected_saras_project_id' => 'selected-project-id']);
    $client = Mockery::mock(SarasClientInterface::class);

    $client->shouldReceive('getProjectsForUser')
        ->with(1, 50)
        ->once()
        ->andReturn(ProjectsResponse::fromArray([
            'projects' => [
                [
                    'id' => 'selected-project-id',
                    'projectMeta' => [
                        'projectId' => 'selected-project-id',
                        'name' => 'Selected DPWH Project',
                    ],
                    'branding' => [
                        'name' => 'Selected Brand',
                    ],
                ],
            ],
        ]));

    $this->app->instance(SarasClientInterface::class, $client);

    $this->actingAs($user)
        ->getJson('/api/saras/context')
        ->assertOk()
        ->assertJsonPath('context.project_id', 'selected-project-id')
        ->assertJsonPath('context.project_name', 'Selected DPWH Project')
        ->assertJsonPath('context.branding.name', 'Selected Brand');
});

test('project context picker persists a selected Saras project', function () {
    config([
        'saras.mode' => 'live',
        'saras.project_id' => 'configured-project-id',
        'branding.remote.enabled' => true,
    ]);

    $user = User::factory()->create();
    $client = Mockery::mock(SarasClientInterface::class);

    $client->shouldReceive('getProjectsForUser')
        ->with(1, 50)
        ->times(3)
        ->andReturn(ProjectsResponse::fromArray([
            'projects' => [
                [
                    'id' => 'configured-project-id',
                    'projectMeta' => [
                        'projectId' => 'configured-project-id',
                        'name' => 'Configured Project',
                    ],
                ],
                [
                    'id' => 'dday-project-id',
                    'projectMeta' => [
                        'projectId' => 'dday-project-id',
                        'name' => 'D-Day Project',
                    ],
                    'branding' => [
                        'name' => 'D-Day Brand',
                    ],
                ],
            ],
        ]));

    $this->app->instance(SarasClientInterface::class, $client);

    $this->actingAs($user)
        ->post('/app/project-context', ['saras_project_id' => 'dday-project-id'])
        ->assertRedirect('/app/project-context');

    expect($user->fresh()->selected_saras_project_id)->toBe('dday-project-id');
});

test('project context picker lists available Saras projects', function () {
    config([
        'saras.mode' => 'live',
        'saras.project_id' => 'configured-project-id',
        'branding.remote.enabled' => true,
    ]);

    $user = User::factory()->create(['selected_saras_project_id' => 'dday-project-id']);
    $client = Mockery::mock(SarasClientInterface::class);

    $client->shouldReceive('getProjectsForUser')
        ->with(1, 50)
        ->andReturn(ProjectsResponse::fromArray([
            'projects' => [
                [
                    'id' => 'configured-project-id',
                    'projectMeta' => [
                        'projectId' => 'configured-project-id',
                        'name' => 'Configured Project',
                    ],
                ],
                [
                    'id' => 'dday-project-id',
                    'projectMeta' => [
                        'projectId' => 'dday-project-id',
                        'name' => 'D-Day Project',
                    ],
                ],
            ],
        ]));

    $this->app->instance(SarasClientInterface::class, $client);
    $this->withoutVite();

    $this->actingAs($user)
        ->get('/app/project-context')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/ProjectContext')
            ->where('activeContext.project_id', 'dday-project-id')
            ->where('projects.1.name', 'D-Day Project')
            ->where('projects.1.is_selected', true)
        );
});

test('project picker falls back to configured project when Saras listing is unavailable', function () {
    config([
        'saras.mode' => 'live',
        'saras.project_id' => 'configured-project-id',
    ]);

    $user = User::factory()->create();
    $client = Mockery::mock(SarasClientInterface::class);

    $client->shouldReceive('getProjectsForUser')
        ->twice()
        ->andThrow(new RuntimeException('Access Denied by IAM Engine.'));

    $this->app->instance(SarasClientInterface::class, $client);
    $this->withoutVite();

    $this->actingAs($user)
        ->get('/app/project-context')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/ProjectContext')
            ->where('projects.0.id', 'configured-project-id')
            ->where('projects.0.is_default', true)
        );
});

test('login response sends unselected multi-project users to the project picker', function () {
    config([
        'saras.mode' => 'live',
        'saras.project_id' => 'configured-project-id',
    ]);

    $user = User::factory()->create(['selected_saras_project_id' => null]);
    $client = Mockery::mock(SarasClientInterface::class);

    $client->shouldReceive('getProjectsForUser')
        ->once()
        ->with(1, 50)
        ->andReturn(ProjectsResponse::fromArray([
            'projects' => [
                ['id' => 'configured-project-id', 'projectMeta' => ['projectId' => 'configured-project-id', 'name' => 'Configured Project']],
                ['id' => 'dday-project-id', 'projectMeta' => ['projectId' => 'dday-project-id', 'name' => 'D-Day Project']],
            ],
        ]));

    $this->app->instance(SarasClientInterface::class, $client);
    $this->actingAs($user);

    $request = Request::create('/login', 'POST');
    $request->setUserResolver(fn () => $user);

    $response = app(LoginResponse::class)->toResponse($request);

    expect($response->getTargetUrl())->toBe(route('app.project-context'));
});

test('login response keeps saved project users on the normal app path', function () {
    $user = User::factory()->create(['selected_saras_project_id' => 'dday-project-id']);

    $this->actingAs($user);

    $request = Request::create('/login', 'POST');
    $request->setUserResolver(fn () => $user);

    $response = app(LoginResponse::class)->toResponse($request);

    expect($response->getTargetUrl())->toBe(url('/app/contracts'));
});

test('branding configuration is shared with inertia pages', function () {
    config([
        'branding.name' => 'DPWH Demo',
        'branding.short_name' => 'DPWH',
        'branding.square_logo' => '/branding/square.png',
        'branding.rectangle_logo' => '/branding/rectangle.png',
    ]);

    User::factory()->create();

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('branding.name', 'DPWH Demo')
            ->where('branding.short_name', 'DPWH')
            ->where('branding.square_logo', '/branding/square.png')
            ->where('branding.rectangle_logo', '/branding/rectangle.png')
        );
});

test('branding resolver can pull identity from Saras project metadata', function () {
    config([
        'saras.mode' => 'live',
        'saras.project_id' => 'dday-project-id',
        'branding.name' => 'Fallback Brand',
        'branding.short_name' => 'Fallback',
        'branding.square_logo' => '/fallback-square.png',
        'branding.rectangle_logo' => '/fallback-rectangle.png',
        'branding.remote.enabled' => true,
    ]);

    $user = User::factory()->create();
    $client = Mockery::mock(SarasClientInterface::class);

    $client->shouldReceive('getProjectsForUser')
        ->once()
        ->with(1, 50)
        ->andReturn(ProjectsResponse::fromArray([
            'projects' => [
                [
                    'id' => 'dday-project-id',
                    'projectMeta' => [
                        'projectId' => 'dday-project-id',
                        'name' => 'DPWH D-Day',
                        'prefix' => 'DPWH',
                    ],
                    'branding' => [
                        'name' => 'DPWH Command Center',
                        'shortName' => 'DPWH',
                        'squareLogo' => 'https://saras.example/square.png',
                        'rectangleLogo' => 'https://saras.example/rectangle.png',
                    ],
                ],
            ],
        ]));

    $this->app->instance(SarasClientInterface::class, $client);

    expect(app(BrandingResolver::class)->resolve($user))->toMatchArray([
        'name' => 'DPWH Command Center',
        'short_name' => 'DPWH',
        'square_logo' => 'https://saras.example/square.png',
        'rectangle_logo' => 'https://saras.example/rectangle.png',
        'source' => 'saras',
        'project_id' => 'dday-project-id',
    ]);
});

test('saras project context resolves subprojects and branding from selected project', function () {
    config([
        'saras.mode' => 'live',
        'saras.project_id' => 'dday-root-project',
        'saras.subproject_ids.attendance' => 'configured-attendance',
        'saras.subproject_ids.trackdata' => 'configured-trackdata',
        'saras.subproject_ids.project_progress' => 'configured-progress',
        'saras.subproject_ids.contract_ai' => 'configured-contracts',
        'branding.name' => 'Fallback Brand',
        'branding.short_name' => 'Fallback',
        'branding.remote.enabled' => true,
    ]);

    $user = User::factory()->create();
    $client = Mockery::mock(SarasClientInterface::class);

    $client->shouldReceive('getProjectsForUser')
        ->once()
        ->with(1, 50)
        ->andReturn(ProjectsResponse::fromArray([
            'projects' => [
                [
                    'id' => 'dday-root-project',
                    'projectMeta' => [
                        'projectId' => 'dday-root-project',
                        'name' => 'DPWH D-Day',
                    ],
                    'branding' => [
                        'name' => 'DPWH Command Center',
                    ],
                    'subProjects' => [
                        [
                            'id' => 'remote-attendance',
                            'projectMeta' => ['projectId' => 'attendance', 'name' => 'Attendance'],
                        ],
                        [
                            'id' => 'remote-trackdata',
                            'projectMeta' => ['projectId' => 'trackdata', 'name' => 'TrackData'],
                        ],
                        [
                            'id' => 'remote-project-progress',
                            'projectMeta' => ['projectId' => 'projectprogress', 'name' => 'Project Progress'],
                        ],
                        [
                            'id' => 'remote-contract-ai',
                            'projectMeta' => ['projectId' => 'bidcontracts', 'name' => 'Bid Contracts'],
                        ],
                    ],
                ],
            ],
        ]));

    $this->app->instance(SarasClientInterface::class, $client);

    $context = app(SarasProjectContextResolver::class)->resolve($user);

    expect($context->source)->toBe('saras')
        ->and($context->projectId)->toBe('dday-root-project')
        ->and($context->subProjectId('attendance'))->toBe('remote-attendance')
        ->and($context->subProjectId('trackdata'))->toBe('remote-trackdata')
        ->and($context->subProjectId('project_progress'))->toBe('remote-project-progress')
        ->and($context->subProjectId('contract_ai'))->toBe('remote-contract-ai')
        ->and($context->branding['name'])->toBe('DPWH Command Center');
});

test('saras project context falls back per missing subproject', function () {
    config([
        'saras.mode' => 'live',
        'saras.project_id' => 'dday-root-project',
        'saras.subproject_ids.attendance' => 'configured-attendance',
        'saras.subproject_ids.trackdata' => 'configured-trackdata',
        'saras.subproject_ids.project_progress' => 'configured-progress',
        'saras.subproject_ids.contract_ai' => 'configured-contracts',
    ]);

    $user = User::factory()->create();
    $client = Mockery::mock(SarasClientInterface::class);

    $client->shouldReceive('getProjectsForUser')
        ->once()
        ->with(1, 50)
        ->andReturn(ProjectsResponse::fromArray([
            'projects' => [
                [
                    'id' => 'dday-root-project',
                    'projectMeta' => ['projectId' => 'dday-root-project', 'name' => 'DPWH D-Day'],
                    'subProjects' => [
                        [
                            'id' => 'remote-attendance',
                            'projectMeta' => ['projectId' => 'attendance', 'name' => 'Attendance'],
                        ],
                    ],
                ],
            ],
        ]));

    $this->app->instance(SarasClientInterface::class, $client);

    $context = app(SarasProjectContextResolver::class)->resolve($user);

    expect($context->subProjectId('attendance'))->toBe('remote-attendance')
        ->and($context->subProjectId('project_progress'))->toBe('configured-progress')
        ->and($context->subprojectSources['attendance'])->toBe('saras')
        ->and($context->subprojectSources['project_progress'])->toBe('config');
});

test('saras project context can discover subprojects from sibling project records', function () {
    config([
        'saras.mode' => 'live',
        'saras.project_id' => 'procureai-root-project',
        'saras.subproject_ids.attendance' => 'configured-attendance',
        'saras.subproject_ids.project_progress' => 'configured-progress',
    ]);

    $user = User::factory()->create();
    $client = Mockery::mock(SarasClientInterface::class);

    $client->shouldReceive('getProjectsForUser')
        ->once()
        ->with(1, 50)
        ->andReturn(ProjectsResponse::fromArray([
            'projects' => [
                [
                    'id' => 'remote-attendance',
                    'projectMeta' => ['projectId' => 'attendance', 'name' => 'Attendance'],
                ],
                [
                    'id' => 'remote-project-progress',
                    'projectMeta' => ['projectId' => 'projectprogress', 'name' => 'Project Progress'],
                ],
            ],
        ]));

    $this->app->instance(SarasClientInterface::class, $client);

    $context = app(SarasProjectContextResolver::class)->resolve($user);

    expect($context->subProjectId('attendance'))->toBe('remote-attendance')
        ->and($context->subProjectId('project_progress'))->toBe('remote-project-progress');
});

test('authenticated users can inspect saras project context readiness', function () {
    config([
        'saras.mode' => 'live',
        'saras.project_id' => 'dday-root-project',
        'saras.subproject_ids.attendance' => 'configured-attendance',
    ]);

    $user = User::factory()->create();
    $client = Mockery::mock(SarasClientInterface::class);

    $client->shouldReceive('getProjectsForUser')
        ->once()
        ->with(1, 50)
        ->andReturn(ProjectsResponse::fromArray([
            'projects' => [
                [
                    'id' => 'dday-root-project',
                    'projectMeta' => ['projectId' => 'dday-root-project', 'name' => 'DPWH D-Day'],
                    'subProjects' => [
                        [
                            'id' => 'remote-attendance',
                            'projectMeta' => ['projectId' => 'attendance', 'name' => 'Attendance'],
                        ],
                    ],
                ],
            ],
        ]));

    $this->app->instance(SarasClientInterface::class, $client);

    $this->actingAs($user)
        ->getJson('/api/saras/context')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('context.project_id', 'dday-root-project')
        ->assertJsonPath('context.subproject_ids.attendance', 'remote-attendance')
        ->assertJsonPath('readiness.branding.square_logo_slot', true)
        ->assertJsonPath('readiness.attendance.status', 'needs-test');
});

test('branding resolver falls back when Saras branding cannot be loaded', function () {
    config([
        'saras.mode' => 'live',
        'saras.project_id' => 'dday-project-id',
        'branding.name' => 'Fallback Brand',
        'branding.short_name' => 'Fallback',
        'branding.square_logo' => '/fallback-square.png',
        'branding.rectangle_logo' => '/fallback-rectangle.png',
        'branding.remote.enabled' => true,
    ]);

    $user = User::factory()->create();
    $client = Mockery::mock(SarasClientInterface::class);

    $client->shouldReceive('getProjectsForUser')
        ->once()
        ->andThrow(new RuntimeException('Access Denied by IAM Engine.'));

    $this->app->instance(SarasClientInterface::class, $client);

    expect(app(BrandingResolver::class)->resolve($user))->toMatchArray([
        'name' => 'Fallback Brand',
        'short_name' => 'Fallback',
        'square_logo' => '/fallback-square.png',
        'rectangle_logo' => '/fallback-rectangle.png',
        'source' => 'config',
        'project_id' => 'dday-project-id',
    ]);
});

test('authenticated inertia pages can share Saras branding', function () {
    config([
        'saras.mode' => 'live',
        'saras.project_id' => 'dday-page-project-id',
        'branding.name' => 'Fallback Brand',
        'branding.short_name' => 'Fallback',
        'branding.square_logo' => '/fallback-square.png',
        'branding.rectangle_logo' => '/fallback-rectangle.png',
        'branding.remote.enabled' => true,
    ]);

    $user = User::factory()->create();
    $client = Mockery::mock(SarasClientInterface::class);

    $client->shouldReceive('getProjectsForUser')
        ->once()
        ->with(1, 50)
        ->andReturn(ProjectsResponse::fromArray([
            'projects' => [
                [
                    'id' => 'dday-page-project-id',
                    'projectMeta' => [
                        'projectId' => 'dday-page-project-id',
                        'name' => 'DPWH D-Day',
                    ],
                    'branding' => [
                        'name' => 'DPWH Field App',
                        'shortName' => 'DPWH',
                        'squareLogo' => 'https://saras.example/page-square.png',
                        'rectangleLogo' => 'https://saras.example/page-rectangle.png',
                    ],
                ],
            ],
        ]));

    $this->app->instance(SarasClientInterface::class, $client);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('branding.name', 'DPWH Field App')
            ->where('branding.short_name', 'DPWH')
            ->where('branding.square_logo', 'https://saras.example/page-square.png')
            ->where('branding.rectangle_logo', 'https://saras.example/page-rectangle.png')
            ->where('branding.source', 'saras')
            ->where('branding.project_id', 'dday-page-project-id')
        );
});
