<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('login page can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using username', function () {
    $user = User::factory()->create([
        'username' => 'testuser123',
        'password' => bcrypt('password'),
    ]);

    $response = $this->post('/login', [
        'username' => 'testuser123',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/app/projects');
});

test('users cannot authenticate with invalid username', function () {
    $user = User::factory()->create([
        'username' => 'testuser123',
        'password' => bcrypt('password'),
    ]);

    $this->post('/login', [
        'username' => 'wronguser',
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('users cannot authenticate with invalid password', function () {
    $user = User::factory()->create([
        'username' => 'testuser123',
        'password' => bcrypt('password'),
    ]);

    $this->post('/login', [
        'username' => 'testuser123',
        'password' => 'wrongpassword',
    ]);

    $this->assertGuest();
});
