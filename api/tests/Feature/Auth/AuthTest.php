<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

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

it('updates the authenticated user\'s name and email', function () {
    $user = actingAsUser();

    $response = $this->putJson('/api/user', [
        'name' => 'Nuevo Nombre',
        'email' => 'nuevo@example.com',
    ]);

    $response->assertOk()->assertJsonPath('user.email', 'nuevo@example.com');

    expect($user->refresh())
        ->name->toBe('Nuevo Nombre')
        ->email->toBe('nuevo@example.com');
});

it('rejects a profile update with an email already used by another user', function () {
    actingAsUser();
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->putJson('/api/user', [
        'name' => 'Nuevo Nombre',
        'email' => 'taken@example.com',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('allows a profile update that keeps the user\'s own current email', function () {
    $user = User::factory()->create(['email' => 'mismo@example.com']);
    $this->actingAs($user, 'sanctum');

    $response = $this->putJson('/api/user', [
        'name' => 'Nuevo Nombre',
        'email' => 'mismo@example.com',
    ]);

    $response->assertOk();
});

it('fetches and stores the BGG avatar when a bgg_username is set', function () {
    $user = actingAsUser();

    Http::fake(fn () => Http::response(
        '<?xml version="1.0"?><user id="1" name="odei"><avatarlink value="https://example.com/avatar.jpg"/></user>'
    ));

    $response = $this->putJson('/api/user', [
        'name' => $user->name,
        'email' => $user->email,
        'bgg_username' => 'odei',
    ]);

    $response->assertOk()->assertJsonPath('user.avatar_url', 'https://example.com/avatar.jpg');
    expect($user->refresh()->bgg_username)->toBe('odei');
});

it('keeps the profile update working when BGG has no avatar for that user', function () {
    $user = actingAsUser();

    Http::fake(fn () => Http::response('<?xml version="1.0"?><user id="1" name="odei"></user>'));

    $response = $this->putJson('/api/user', [
        'name' => $user->name,
        'email' => $user->email,
        'bgg_username' => 'odei',
    ]);

    $response->assertOk()->assertJsonPath('user.avatar_url', null);
});

it('does not refetch the avatar when the bgg_username has not changed', function () {
    $user = actingAsUser();
    $user->update(['bgg_username' => 'odei', 'avatar_url' => 'https://example.com/old.jpg']);

    Http::fake(fn () => Http::response('should not be called', 500));

    $response = $this->putJson('/api/user', [
        'name' => $user->name,
        'email' => $user->email,
        'bgg_username' => 'odei',
    ]);

    $response->assertOk()->assertJsonPath('user.avatar_url', 'https://example.com/old.jpg');
    Http::assertNothingSent();
});

it('clears the avatar when the bgg_username is removed', function () {
    $user = actingAsUser();
    $user->update(['bgg_username' => 'odei', 'avatar_url' => 'https://example.com/old.jpg']);

    $response = $this->putJson('/api/user', [
        'name' => $user->name,
        'email' => $user->email,
        'bgg_username' => null,
    ]);

    $response->assertOk()->assertJsonPath('user.avatar_url', null);
    expect($user->refresh()->bgg_username)->toBeNull();
});

it('changes the password when the current one is correct', function () {
    $user = User::factory()->create(['password' => bcrypt('old-password')]);
    $this->actingAs($user, 'sanctum');

    $response = $this->putJson('/api/user/password', [
        'current_password' => 'old-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertNoContent();

    Auth::forgetGuards();

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'new-password',
        'device_name' => 'test-suite',
    ])->assertOk();
});

it('rejects a password change with the wrong current password', function () {
    $user = User::factory()->create(['password' => bcrypt('old-password')]);
    $this->actingAs($user, 'sanctum');

    $response = $this->putJson('/api/user/password', [
        'current_password' => 'wrong-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('current_password');
});
