<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('registers a new user and returns a usable token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Odei',
        'email' => 'odei@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'test-suite',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.email', 'odei@example.com')
        ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

    $this->assertDatabaseHas('users', ['email' => 'odei@example.com']);

    $token = $response->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('email', 'odei@example.com');
});

it('rejects registration with a mismatched password confirmation', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Odei',
        'email' => 'odei@example.com',
        'password' => 'password',
        'password_confirmation' => 'something-else',
        'device_name' => 'test-suite',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('rejects registration with an email already in use', function () {
    User::factory()->create(['email' => 'odei@example.com']);

    $response = $this->postJson('/api/register', [
        'name' => 'Otro',
        'email' => 'odei@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'test-suite',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('logs in an existing user with correct credentials', function () {
    User::factory()->create([
        'email' => 'odei@example.com',
        'password' => bcrypt('correct-password'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'odei@example.com',
        'password' => 'correct-password',
        'device_name' => 'test-suite',
    ]);

    $response->assertOk()->assertJsonStructure(['user', 'token']);
});

it('rejects login with an incorrect password', function () {
    User::factory()->create([
        'email' => 'odei@example.com',
        'password' => bcrypt('correct-password'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'odei@example.com',
        'password' => 'wrong-password',
        'device_name' => 'test-suite',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('rejects login for an email that does not exist', function () {
    $response = $this->postJson('/api/login', [
        'email' => 'nobody@example.com',
        'password' => 'whatever',
        'device_name' => 'test-suite',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('logs out and revokes the current token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-suite')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/logout')
        ->assertNoContent();

    // Sanctum's guard caches the resolved user for the lifetime of the
    // container; within a single test that container is shared across both
    // calls above (unlike separate real requests, which each get a fresh
    // one), so the guard must be reset to prove the token is really revoked.
    Auth::forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/user')
        ->assertUnauthorized();
});

it('rejects unauthenticated access to protected routes', function () {
    $this->getJson('/api/user')->assertUnauthorized();
});
