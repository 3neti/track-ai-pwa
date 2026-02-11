<?php

use App\Models\Project;
use App\Models\Upload;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::create([
        'external_id' => 'TEST-PROJECT-001',
        'name' => 'Test Project',
        'description' => 'A test project',
        'status' => 'active',
    ]);
});

test('uploads can be listed by project', function () {
    // Create some uploads
    Upload::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'contract_id' => $this->project->external_id,
        'title' => 'Test Upload 1',
        'document_type' => 'equipment_pictures',
        'tags' => ['test'],
        'status' => 'uploaded',
        'client_request_id' => fake()->uuid(),
    ]);

    Upload::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'contract_id' => $this->project->external_id,
        'title' => 'Test Upload 2',
        'document_type' => 'delivery_receipts',
        'tags' => ['test'],
        'status' => 'pending',
        'client_request_id' => fake()->uuid(),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/projects/{$this->project->id}/uploads");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [['id', 'title', 'status', 'document_type']],
            'meta' => ['current_page', 'total'],
        ])
        ->assertJson(['success' => true]);

    expect($response->json('meta.total'))->toBe(2);
});

test('pending upload can be created', function () {
    $clientRequestId = fake()->uuid();

    $response = $this->actingAs($this->user)
        ->postJson("/api/projects/{$this->project->id}/uploads", [
            'client_request_id' => $clientRequestId,
            'contract_id' => $this->project->external_id,
            'title' => 'New Upload',
            'document_type' => 'equipment_pictures',
            'tags' => ['daily', 'inspection'],
            'remarks' => 'Test remarks',
        ]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => 'Upload enqueued successfully.',
        ]);

    $this->assertDatabaseHas('uploads', [
        'client_request_id' => $clientRequestId,
        'title' => 'New Upload',
        'status' => 'pending',
    ]);

    // Verify audit log was created
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->user->id,
        'action' => 'upload_enqueued',
    ]);
});

test('upload metadata can be updated when not locked', function () {
    $upload = Upload::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'contract_id' => $this->project->external_id,
        'title' => 'Original Title',
        'document_type' => 'equipment_pictures',
        'status' => 'uploaded',
        'client_request_id' => fake()->uuid(),
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/projects/{$this->project->id}/uploads/{$upload->id}", [
            'title' => 'Updated Title',
            'remarks' => 'Updated remarks',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'upload' => ['title' => 'Updated Title'],
        ]);

    $this->assertDatabaseHas('uploads', [
        'id' => $upload->id,
        'title' => 'Updated Title',
        'remarks' => 'Updated remarks',
    ]);

    // Verify audit log
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->user->id,
        'action' => 'upload_metadata_updated',
    ]);
});

test('upload cannot be updated when locked', function () {
    $upload = Upload::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'contract_id' => $this->project->external_id,
        'title' => 'Locked Upload',
        'document_type' => 'equipment_pictures',
        'status' => 'uploaded',
        'client_request_id' => fake()->uuid(),
        'locked_at' => now(),
        'locked_reason' => 'Referenced in progress submission',
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/projects/{$this->project->id}/uploads/{$upload->id}", [
            'title' => 'Should Not Update',
        ]);

    $response->assertStatus(423)
        ->assertJson([
            'success' => false,
        ]);

    // Title should remain unchanged
    $this->assertDatabaseHas('uploads', [
        'id' => $upload->id,
        'title' => 'Locked Upload',
    ]);
});

test('upload cannot be updated when project closed', function () {
    $this->project->update(['status' => 'closed']);

    $upload = Upload::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'contract_id' => $this->project->external_id,
        'title' => 'Upload in Closed Project',
        'document_type' => 'equipment_pictures',
        'status' => 'uploaded',
        'client_request_id' => fake()->uuid(),
    ]);

    $response = $this->actingAs($this->user)
        ->patchJson("/api/projects/{$this->project->id}/uploads/{$upload->id}", [
            'title' => 'Should Not Update',
        ]);

    $response->assertStatus(423);
});

test('uploaded soft delete creates audit log', function () {
    $upload = Upload::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'contract_id' => $this->project->external_id,
        'title' => 'Upload to Delete',
        'document_type' => 'equipment_pictures',
        'status' => 'uploaded',
        'entry_id' => 'ENT-12345',
        'client_request_id' => fake()->uuid(),
    ]);

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/projects/{$this->project->id}/uploads/{$upload->id}", [
            'reason' => 'Duplicate upload',
        ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    // Soft deleted - still in DB but with deleted_at and status=deleted
    $this->assertSoftDeleted('uploads', ['id' => $upload->id]);
    $this->assertDatabaseHas('uploads', [
        'id' => $upload->id,
        'status' => 'deleted',
    ]);

    // Verify audit log
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->user->id,
        'action' => 'upload_deleted',
    ]);
});

test('pending upload hard deletes', function () {
    $upload = Upload::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'contract_id' => $this->project->external_id,
        'title' => 'Pending Upload to Delete',
        'document_type' => 'equipment_pictures',
        'status' => 'pending',
        'client_request_id' => fake()->uuid(),
    ]);

    $uploadId = $upload->id;

    $response = $this->actingAs($this->user)
        ->deleteJson("/api/projects/{$this->project->id}/uploads/{$upload->id}");

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    // Hard deleted - completely gone from DB
    $this->assertDatabaseMissing('uploads', ['id' => $uploadId]);
});

test('failed upload can be retried', function () {
    $upload = Upload::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'contract_id' => $this->project->external_id,
        'title' => 'Failed Upload',
        'document_type' => 'equipment_pictures',
        'status' => 'failed',
        'last_error' => 'Network timeout',
        'client_request_id' => fake()->uuid(),
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/projects/{$this->project->id}/uploads/{$upload->id}/retry");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'upload' => ['status' => 'pending'],
        ]);

    $this->assertDatabaseHas('uploads', [
        'id' => $upload->id,
        'status' => 'pending',
        'last_error' => null,
    ]);

    // Verify audit log
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $this->user->id,
        'action' => 'upload_retry',
    ]);
});

test('client_request_id enforces idempotency', function () {
    $clientRequestId = fake()->uuid();

    // First request succeeds
    $response1 = $this->actingAs($this->user)
        ->postJson("/api/projects/{$this->project->id}/uploads", [
            'client_request_id' => $clientRequestId,
            'contract_id' => $this->project->external_id,
            'title' => 'First Upload',
            'document_type' => 'equipment_pictures',
        ]);

    $response1->assertStatus(201);

    // Second request with same client_request_id fails validation
    $response2 = $this->actingAs($this->user)
        ->postJson("/api/projects/{$this->project->id}/uploads", [
            'client_request_id' => $clientRequestId,
            'contract_id' => $this->project->external_id,
            'title' => 'Duplicate Upload',
            'document_type' => 'equipment_pictures',
        ]);

    $response2->assertStatus(422)
        ->assertJsonValidationErrors(['client_request_id']);

    // Only one upload should exist
    expect(Upload::where('client_request_id', $clientRequestId)->count())->toBe(1);
});

test('uploads can be filtered by status', function () {
    Upload::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'contract_id' => $this->project->external_id,
        'title' => 'Uploaded Item',
        'document_type' => 'equipment_pictures',
        'status' => 'uploaded',
        'client_request_id' => fake()->uuid(),
    ]);

    Upload::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'contract_id' => $this->project->external_id,
        'title' => 'Pending Item',
        'document_type' => 'equipment_pictures',
        'status' => 'pending',
        'client_request_id' => fake()->uuid(),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/projects/{$this->project->id}/uploads?status=pending");

    $response->assertStatus(200);
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data.0.status'))->toBe('pending');
});
