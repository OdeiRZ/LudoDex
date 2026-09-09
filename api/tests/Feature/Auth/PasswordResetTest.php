<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
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

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

it('points the reset link at the frontend, not a server-rendered route', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'odei@example.com']);

    $this->postJson('/api/forgot-password', ['email' => 'odei@example.com']);

    Notification::assertSentTo(
        $user,
        ResetPasswordNotification::class,
        function (ResetPasswordNotification $notification) use ($user) {
            $mail = $notification->toMail($user);
            $url = $mail->actionUrl;

            expect($url)->toStartWith('http://localhost:5173/reset-password?token=')
                ->and($url)->toContain('email=odei%40example.com');

            return true;
        }
    );
});

it('responds the same way for an email that does not exist, to avoid leaking which emails are registered', function () {
    Notification::fake();

    $this->withHeader('Accept-Language', 'es')
        ->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
        ->assertOk()
        ->assertJsonPath('message', 'Te hemos enviado por email el enlace para restablecer la contraseña.');

    // La respuesta es idéntica a la de un email real, pero por debajo
    // Password::sendResetLink() no envía nada - nadie recibe un email para
    // una cuenta que no existe, solo cambia lo que ve quien hace la petición.
    Notification::assertNothingSent();
});

it('responds the same way when a reset was already requested moments ago, not a distinct throttled message', function () {
    Notification::fake();

    User::factory()->create(['email' => 'odei@example.com']);

    $this->postJson('/api/forgot-password', ['email' => 'odei@example.com'])->assertOk();

    // Pedirlo de nuevo enseguida entra en el throttle interno de Laravel
    // (Password::RESET_THROTTLED) - antes de esta corrección, ese estado
    // también generaba un mensaje propio, otra forma sutil de distinguir
    // un email registrado de uno que no lo está.
    $this->withHeader('Accept-Language', 'es')
        ->postJson('/api/forgot-password', ['email' => 'odei@example.com'])
        ->assertOk()
        ->assertJsonPath('message', 'Te hemos enviado por email el enlace para restablecer la contraseña.');
});

it('resets the password with a valid token and lets the user log in with it', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'odei@example.com',
        'password' => bcrypt('old-password'),
    ]);

    $this->postJson('/api/forgot-password', ['email' => 'odei@example.com']);

    $token = null;
    Notification::assertSentTo(
        $user,
        ResetPasswordNotification::class,
        function (ResetPasswordNotification $notification) use (&$token) {
            $token = $notification->token;

            return true;
        }
    );

    $this->withHeader('Accept-Language', 'es')
        ->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'odei@example.com',
            'password' => 'new-password1',
            'password_confirmation' => 'new-password1',
        ])->assertOk()->assertJsonPath('message', 'Tu contraseña se ha restablecido.');

    $this->postJson('/api/login', [
        'email' => 'odei@example.com',
        'password' => 'new-password1',
        'device_name' => 'test-suite',
    ])->assertOk();
});

it('revokes every existing token on a successful reset', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'odei@example.com',
        'password' => bcrypt('old-password'),
    ]);
    $staleToken = $user->createToken('stolen-or-elsewhere')->plainTextToken;

    $this->postJson('/api/forgot-password', ['email' => 'odei@example.com']);

    $token = null;
    Notification::assertSentTo(
        $user,
        ResetPasswordNotification::class,
        function (ResetPasswordNotification $notification) use (&$token) {
            $token = $notification->token;

            return true;
        }
    );

    $this->postJson('/api/reset-password', [
        'token' => $token,
        'email' => 'odei@example.com',
        'password' => 'new-password1',
        'password_confirmation' => 'new-password1',
    ])->assertOk();

    // Unlike the authenticated /user/password change, a reset has no
    // "request I'm making right now" token to preserve - the reset
    // itself isn't authenticated at all.
    $this->withToken($staleToken)->getJson('/api/user')->assertUnauthorized();
});

it('rejects an invalid reset token', function () {
    User::factory()->create(['email' => 'odei@example.com']);

    $this->withHeader('Accept-Language', 'es')
        ->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'odei@example.com',
            'password' => 'new-password1',
            'password_confirmation' => 'new-password1',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Ese enlace para restablecer la contraseña no es válido.');
});

it('sends the reset email branded as LudoDex, in Spanish', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'odei@example.com']);

    $this->withHeader('Accept-Language', 'es')
        ->postJson('/api/forgot-password', ['email' => 'odei@example.com']);

    Notification::assertSentTo(
        $user,
        ResetPasswordNotification::class,
        function (ResetPasswordNotification $notification) use ($user) {
            $mail = $notification->toMail($user);

            expect($mail->subject)->toBe('Restablece tu contraseña de LudoDex')
                ->and($mail->greeting)->toBe('¡Hola!')
                ->and($mail->introLines)->toContain('Recibes este email porque hemos recibido una solicitud para restablecer la contraseña de tu cuenta de LudoDex.')
                ->and($mail->actionText)->toBe('Restablecer contraseña')
                ->and($mail->salutation)->toBe("Un saludo,\nEl equipo de LudoDex");

            return true;
        }
    );
});

it('sends the reset email branded as LudoDex, in English', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'odei@example.com']);

    $this->withHeader('Accept-Language', 'en')
        ->postJson('/api/forgot-password', ['email' => 'odei@example.com']);

    Notification::assertSentTo(
        $user,
        ResetPasswordNotification::class,
        function (ResetPasswordNotification $notification) use ($user) {
            $mail = $notification->toMail($user);

            expect($mail->subject)->toBe('Reset your LudoDex password')
                ->and($mail->greeting)->toBe('Hello!')
                ->and($mail->actionText)->toBe('Reset password')
                ->and($mail->salutation)->toBe("Best,\nThe LudoDex team");

            return true;
        }
    );
});
