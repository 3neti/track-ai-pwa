<?php

use App\Models\AttendanceSession;
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
        'attendance_status',
        'session',
    ]);
    $response->assertJson([
        'success' => true,
        'attendance_status' => 'checked_in',
    ]);

    // Verify session was created
    $this->assertDatabaseHas('attendance_sessions', [
        'user_id' => $user->id,
        'project_external_id' => 'CONTRACT-001',
        'status' => 'open',
    ]);
});

test('authenticated users can check out after checking in', function () {
    $user = User::factory()->create();

    // First check in
    $this->actingAs($user)->postJson('/api/attendance/check-in', [
        'contract_id' => 'CONTRACT-001',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
    ]);

    // Then check out
    $response = $this->actingAs($user)->postJson('/api/attendance/check-out', [
        'contract_id' => 'CONTRACT-001',
        'latitude' => 14.5996,
        'longitude' => 120.9843,
        'remarks' => 'Test check-out',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'success',
        'entry_id',
        'message',
        'attendance_status',
        'session',
    ]);
    $response->assertJson([
        'success' => true,
        'attendance_status' => 'checked_out',
    ]);

    // Verify session was closed
    $this->assertDatabaseHas('attendance_sessions', [
        'user_id' => $user->id,
        'project_external_id' => 'CONTRACT-001',
        'status' => 'closed',
    ]);
});

test('cannot check in twice without checking out', function () {
    $user = User::factory()->create();

    // First check in
    $this->actingAs($user)->postJson('/api/attendance/check-in', [
        'contract_id' => 'CONTRACT-001',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
    ]);

    // Try to check in again
    $response = $this->actingAs($user)->postJson('/api/attendance/check-in', [
        'contract_id' => 'CONTRACT-001',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'success' => false,
        'attendance_status' => 'checked_in',
    ]);
    expect($response->json('message'))->toContain('Already checked in');
});

test('cannot check out without checking in', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/attendance/check-out', [
        'contract_id' => 'CONTRACT-001',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
    ]);

    $response->assertStatus(200);
    $response->assertJson([
        'success' => false,
        'attendance_status' => 'checked_out',
    ]);
    expect($response->json('message'))->toContain('Not checked in');
});

test('can get attendance status', function () {
    $user = User::factory()->create();

    // Initially checked out
    $response = $this->actingAs($user)->getJson('/api/attendance/status?contract_id=CONTRACT-001');
    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'attendance_status' => 'checked_out',
        'session' => null,
    ]);

    // Check in
    $this->actingAs($user)->postJson('/api/attendance/check-in', [
        'contract_id' => 'CONTRACT-001',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
    ]);

    // Now should be checked in
    $response = $this->actingAs($user)->getJson('/api/attendance/status?contract_id=CONTRACT-001');
    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'attendance_status' => 'checked_in',
    ]);
    expect($response->json('session'))->not->toBeNull();
});

test('previous day sessions are auto-closed on new action', function () {
    $user = User::factory()->create();

    // Create an orphaned session from yesterday
    AttendanceSession::create([
        'user_id' => $user->id,
        'project_external_id' => 'CONTRACT-001',
        'check_in_at' => now()->subDay()->setTime(9, 0),
        'check_in_latitude' => 14.5995,
        'check_in_longitude' => 120.9842,
        'status' => 'open',
    ]);

    // Check status - should auto-close the orphaned session
    $response = $this->actingAs($user)->getJson('/api/attendance/status?contract_id=CONTRACT-001');

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'attendance_status' => 'checked_out', // New session is closed
    ]);
    expect($response->json('auto_closed_session'))->not->toBeNull();

    // Verify the session was auto-closed
    $this->assertDatabaseHas('attendance_sessions', [
        'user_id' => $user->id,
        'status' => 'auto_closed',
        'auto_closed_reason' => 'previous_day_unclosed',
    ]);
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

test('status endpoint requires contract_id', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/attendance/status');

    $response->assertStatus(422);
    $response->assertJson(['success' => false]);
});
