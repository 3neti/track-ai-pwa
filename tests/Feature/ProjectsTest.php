<?php

use App\Models\Project;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests cannot access projects page', function () {
    $response = $this->get('/app/projects');

    $response->assertRedirect('/login');
});

test('authenticated users can view projects page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/app/projects');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('app/Projects'));
});

test('authenticated users can sync projects', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/projects/sync');

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'success',
        'projects',
        'message',
    ]);
    $response->assertJson(['success' => true]);
});

test('projects are displayed on the page', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'name' => 'Test Project',
        'external_id' => 'EXT-001',
    ]);

    $response = $this->actingAs($user)->get('/app/projects');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('app/Projects')
        ->has('projects', 1)
        ->where('projects.0.name', 'Test Project')
    );
});
