<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

// The test client defaults to an "en-us,en;q=0.5" Accept-Language header
// when none is given (a Symfony Request::create() default, not a browser
// preference), which would otherwise make every "expect Spanish" assertion
// below flaky against SetLocaleFromHeader - so tests that check Spanish
// wording set it explicitly, the same way a real request from the SPA
// always would.

it('sends a reset link notification for an existing email', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'odei@example.com']);

    $this->withHeader('Accept-Language', 'es')
        ->postJson('/api/forgot-password', ['email' => 'odei@example.com'])
        ->assertOk()
        ->assertJsonPath('message', 'Te hemos enviado por email el enlace para restablecer la contraseña.');

    Notification::assertSentTo($user, ResetPassword::class);
});

it('points the reset link at the frontend, not a server-rendered route', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'odei@example.com']);

    $this->postJson('/api/forgot-password', ['email' => 'odei@example.com']);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $mail = $notification->toMail($user);
        $url = $mail->actionUrl;

        expect($url)->toStartWith('http://localhost:5173/reset-password?token=')
            ->and($url)->toContain('email=odei%40example.com');

        return true;
    });
});

it('rejects a reset link request for an email that does not exist', function () {
    Notification::fake();

    $this->withHeader('Accept-Language', 'es')
        ->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'No encontramos ningún usuario con ese email.');

    Notification::assertNothingSent();
});

it('resets the password with a valid token and lets the user log in with it', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'odei@example.com',
        'password' => bcrypt('old-password'),
    ]);

    $this->postJson('/api/forgot-password', ['email' => 'odei@example.com']);

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    $this->withHeader('Accept-Language', 'es')
        ->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'odei@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()->assertJsonPath('message', 'Tu contraseña se ha restablecido.');

    $this->postJson('/api/login', [
        'email' => 'odei@example.com',
        'password' => 'new-password',
        'device_name' => 'test-suite',
    ])->assertOk();
});

it('rejects an invalid reset token', function () {
    User::factory()->create(['email' => 'odei@example.com']);

    $this->withHeader('Accept-Language', 'es')
        ->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'odei@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Ese enlace para restablecer la contraseña no es válido.');
});

it('returns password reset messages in English when Accept-Language: en is sent', function () {
    Notification::fake();

    $this->withHeader('Accept-Language', 'en')
        ->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', "We can't find a user with that email address.");
});
