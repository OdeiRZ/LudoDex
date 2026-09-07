<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function signedVerificationUrl(User $user, ?string $hash = null): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => $hash ?? sha1($user->getEmailForVerification()),
    ]);
}

it('sends a verification email when registering', function () {
    Notification::fake();

    $this->postJson('/api/register', [
        'name' => 'Odei',
        'email' => 'odei@example.com',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
        'device_name' => 'test',
    ])->assertCreated();

    $user = User::where('email', 'odei@example.com')->sole();
    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('marks the email verified and redirects to the frontend with ok=1 on a valid link', function () {
    $user = User::factory()->unverified()->create();

    $this->get(signedVerificationUrl($user))
        ->assertRedirect('http://localhost:5173/verify-email?ok=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('redirects with ok=0 and leaves the email unverified when the signature is invalid', function () {
    $user = User::factory()->unverified()->create();

    $this->get(signedVerificationUrl($user).'&tampered=1')
        ->assertRedirect('http://localhost:5173/verify-email?ok=0');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('redirects with ok=0 and leaves the email unverified when the hash does not match the user', function () {
    $user = User::factory()->unverified()->create();

    $this->get(signedVerificationUrl($user, sha1('someone-else@example.com')))
        ->assertRedirect('http://localhost:5173/verify-email?ok=0');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('still redirects with ok=1 when the link is visited twice, without erroring', function () {
    $user = User::factory()->unverified()->create();
    $url = signedVerificationUrl($user);

    $this->get($url)->assertRedirect('http://localhost:5173/verify-email?ok=1');

    $this->get($url)->assertRedirect('http://localhost:5173/verify-email?ok=1');
});

it('resends the verification notification for an unverified user', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->withHeader('Accept-Language', 'es')
        ->actingAs($user, 'sanctum')
        ->postJson('/api/email/verification-notification')
        ->assertOk()
        ->assertJsonPath('message', 'Te hemos enviado un nuevo enlace de verificación.');

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('does not resend and says so when the user is already verified', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->withHeader('Accept-Language', 'es')
        ->actingAs($user, 'sanctum')
        ->postJson('/api/email/verification-notification')
        ->assertOk()
        ->assertJsonPath('message', 'Tu email ya está verificado.');

    Notification::assertNothingSent();
});

it('rejects a resend request from a guest', function () {
    $this->postJson('/api/email/verification-notification')->assertUnauthorized();
});

it('sends the verification email branded as LudoDex, in Spanish', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create(['email' => 'odei@example.com']);

    $this->withHeader('Accept-Language', 'es')
        ->actingAs($user, 'sanctum')
        ->postJson('/api/email/verification-notification');

    Notification::assertSentTo(
        $user,
        VerifyEmailNotification::class,
        function (VerifyEmailNotification $notification) use ($user) {
            $mail = $notification->toMail($user);

            expect($mail->subject)->toBe('Verifica tu email de LudoDex')
                ->and($mail->greeting)->toBe('¡Hola!')
                ->and($mail->actionText)->toBe('Verificar email')
                ->and($mail->salutation)->toBe("Un saludo,\nEl equipo de LudoDex");

            return true;
        }
    );
});

it('sends the verification email branded as LudoDex, in English', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create(['email' => 'odei@example.com']);

    $this->withHeader('Accept-Language', 'en')
        ->actingAs($user, 'sanctum')
        ->postJson('/api/email/verification-notification');

    Notification::assertSentTo(
        $user,
        VerifyEmailNotification::class,
        function (VerifyEmailNotification $notification) use ($user) {
            $mail = $notification->toMail($user);

            expect($mail->subject)->toBe('Verify your LudoDex email')
                ->and($mail->greeting)->toBe('Hello!')
                ->and($mail->actionText)->toBe('Verify email')
                ->and($mail->salutation)->toBe("Best,\nThe LudoDex team");

            return true;
        }
    );
});
