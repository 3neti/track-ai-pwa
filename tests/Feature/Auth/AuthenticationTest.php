<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create([
        'username' => 'testuser',
    ]);

    $response = $this->post(route('login.store'), [
        'username' => 'testuser',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/app/projects');
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    if (! Features::canManageTwoFactorAuthentication()) {
        $this->markTestSkipped('Two-factor authentication is not enabled.');
    }

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create([
        'username' => 'twofactoruser',
    ]);

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->post(route('login'), [
        'username' => 'twofactoruser',
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create([
        'username' => 'testinvalid',
    ]);

    $this->post(route('login.store'), [
        'username' => 'testinvalid',
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('home'));
});

test('users are rate limited', function () {
    $user = User::factory()->create([
        'username' => 'ratelimited',
    ]);

    RateLimiter::increment(md5('login'.implode('|', ['ratelimited', '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'username' => 'ratelimited',
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});
