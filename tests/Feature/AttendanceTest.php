<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests cannot access attendance page', function () {
    $response = $this->get('/app/attendance');

    $response->assertRedirect('/login');
});

test('authenticated users can view attendance page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/app/attendance');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('app/Attendance'));
});

test('authenticated users can check in', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/attendance/check-in', [
        'contract_id' => 'CONTRACT-001',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'remarks' => 'Test check-in',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'success',
        'entry_id',
        'message',
    ]);
    $response->assertJson(['success' => true]);
});

test('authenticated users can check out', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/attendance/check-out', [
        'contract_id' => 'CONTRACT-001',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'remarks' => 'Test check-out',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'success',
        'entry_id',
        'message',
    ]);
    $response->assertJson(['success' => true]);
});

test('check-in requires contract_id', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/attendance/check-in', [
        'latitude' => 14.5995,
        'longitude' => 120.9842,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['contract_id']);
});

test('check-in requires location coordinates', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/attendance/check-in', [
        'contract_id' => 'CONTRACT-001',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['latitude', 'longitude']);
});
