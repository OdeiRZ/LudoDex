<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

it('registers a new user and returns a usable token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Odei',
        'email' => 'odei@example.com',
        'password' => 'password1',
        'password_confirmation' => 'password1',
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

it('accepts an 8-character password but rejects a 7-character one', function () {
    // Letters+number held constant across both - isolates the length
    // check from the separate letters()/numbers() checks below.
    $short = $this->postJson('/api/register', [
        'name' => 'Odei',
        'email' => 'odei@example.com',
        'password' => 'abcdef1',
        'password_confirmation' => 'abcdef1',
        'device_name' => 'test-suite',
    ]);

    $short->assertUnprocessable()->assertJsonValidationErrors('password');

    $minimum = $this->postJson('/api/register', [
        'name' => 'Odei',
        'email' => 'odei@example.com',
        'password' => 'abcdefg1',
        'password_confirmation' => 'abcdefg1',
        'device_name' => 'test-suite',
    ]);

    $minimum->assertCreated();
});

it('rejects a password with no numbers', function () {
    $this->postJson('/api/register', [
        'name' => 'Odei',
        'email' => 'odei@example.com',
        'password' => 'onlyletters',
        'password_confirmation' => 'onlyletters',
        'device_name' => 'test-suite',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('rejects a password with no letters', function () {
    $this->postJson('/api/register', [
        'name' => 'Odei',
        'email' => 'odei@example.com',
        'password' => '12345678',
        'password_confirmation' => '12345678',
        'device_name' => 'test-suite',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('rejects registration with a mismatched password confirmation', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Odei',
        'email' => 'odei@example.com',
        'password' => 'password1',
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
        'password' => 'password1',
        'password_confirmation' => 'password1',
        'device_name' => 'test-suite',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('returns validation messages in Spanish when Accept-Language: es is sent', function () {
    User::factory()->create(['email' => 'odei@example.com']);

    $response = $this->withHeader('Accept-Language', 'es')->postJson('/api/register', [
        'name' => 'Otro',
        'email' => 'odei@example.com',
        'password' => 'password1',
        'password_confirmation' => 'password1',
        'device_name' => 'test-suite',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'El email ya está en uso.');
});

it('returns validation messages in English when Accept-Language: en is sent', function () {
    User::factory()->create(['email' => 'odei@example.com']);

    $response = $this->withHeader('Accept-Language', 'en')->postJson('/api/register', [
        'name' => 'Otro',
        'email' => 'odei@example.com',
        'password' => 'password1',
        'password_confirmation' => 'password1',
        'device_name' => 'test-suite',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'The email has already been taken.');
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

it('returns a clean 401 for an unauthenticated request without an Accept header', function () {
    // getJson() above sends Accept: application/json itself, which never
    // exercised this - Laravel's own default unauthenticated handling only
    // renders JSON when that header is present, and otherwise falls back
    // to redirecting to a named "login" route this app doesn't have,
    // turning into a 500 instead of a 401 (found directly). A plain
    // Http::withToken(...)->get(...) call - no Accept header set - is
    // exactly this case.
    $this->get('/api/user')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
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

it('resets email verification and sends a new verification email when the email actually changes', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'viejo@example.com']);
    expect($user->email_verified_at)->not->toBeNull();
    $this->actingAs($user, 'sanctum');

    $this->putJson('/api/user', [
        'name' => $user->name,
        'email' => 'nuevo@example.com',
    ])->assertOk();

    expect($user->fresh()->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('does not reset email verification or send anything when the email is unchanged', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'mismo@example.com']);
    expect($user->email_verified_at)->not->toBeNull();
    $this->actingAs($user, 'sanctum');

    $this->putJson('/api/user', [
        'name' => 'Nuevo Nombre',
        'email' => 'mismo@example.com',
    ])->assertOk();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});

it('persists the discoverable flag via the profile update endpoint', function () {
    $user = User::factory()->create(['name' => 'Odei', 'email' => 'odei@example.com', 'discoverable' => false]);
    $this->actingAs($user, 'sanctum');

    $this->putJson('/api/user', [
        'name' => 'Odei',
        'email' => 'odei@example.com',
        'discoverable' => true,
    ])->assertOk()->assertJsonPath('user.discoverable', true);

    expect($user->fresh()->discoverable)->toBeTrue();
});

it('keeps discoverable false by default when a profile update omits it', function () {
    $user = User::factory()->create(['name' => 'Odei', 'email' => 'odei@example.com', 'discoverable' => false]);
    $this->actingAs($user, 'sanctum');

    $this->putJson('/api/user', ['name' => 'Odei', 'email' => 'odei@example.com'])->assertOk();

    expect($user->fresh()->discoverable)->toBeFalse();
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
        'password' => 'new-password1',
        'password_confirmation' => 'new-password1',
    ]);

    $response->assertNoContent();

    Auth::forgetGuards();

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'new-password1',
        'device_name' => 'test-suite',
    ])->assertOk();
});

it('rejects a password change with the wrong current password', function () {
    $user = User::factory()->create(['password' => bcrypt('old-password')]);
    $this->actingAs($user, 'sanctum');

    $response = $this->putJson('/api/user/password', [
        'current_password' => 'wrong-password',
        'password' => 'new-password1',
        'password_confirmation' => 'new-password1',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('current_password');
});

it('revokes every other token when changing the password, but keeps the one making the request', function () {
    // Real Bearer tokens here, not actingAs('sanctum') (a session-guard
    // bypass with no real PersonalAccessToken row) - the whole point is
    // to prove the OTHER token stops working and THIS one still does.
    $user = User::factory()->create(['password' => bcrypt('old-password')]);
    $keptToken = $user->createToken('kept')->plainTextToken;
    $otherToken = $user->createToken('stolen-or-elsewhere')->plainTextToken;

    $this->withToken($keptToken)->putJson('/api/user/password', [
        'current_password' => 'old-password',
        'password' => 'new-password1',
        'password_confirmation' => 'new-password1',
    ])->assertNoContent();

    // Sanctum's guard caches the resolved user for the lifetime of the
    // test's app instance - without this, the next simulated request
    // below would silently reuse the first request's resolution instead
    // of genuinely re-checking the (now-deleted) token, same reason the
    // password-change-then-relogin test above needs it too.
    Auth::forgetGuards();
    $this->withToken($otherToken)->getJson('/api/user')->assertUnauthorized();

    Auth::forgetGuards();
    $this->withToken($keptToken)->getJson('/api/user')->assertOk();
});
