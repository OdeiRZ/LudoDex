<?php

use App\Mail\Transport\GmailApiTransport;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Email;

function fakeEmail(): Email
{
    return (new Email)
        ->from('soporteludodex@gmail.com')
        ->to('destinatario@example.com')
        ->subject('Restablecer contraseña')
        ->text('Pulsa el enlace para restablecer tu contraseña.');
}

it('exchanges the refresh token for an access token, then sends the raw message via the Gmail API', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token']),
        'gmail.googleapis.com/*' => Http::response(['id' => 'abc123']),
    ]);

    (new GmailApiTransport('client-id', 'client-secret', 'refresh-token'))->send(fakeEmail());

    Http::assertSent(function ($request) {
        return $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret'
            && $request['refresh_token'] === 'refresh-token'
            && $request['grant_type'] === 'refresh_token';
    });

    Http::assertSent(function ($request) {
        return $request->url() === 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'
            && $request->hasHeader('Authorization', 'Bearer fake-access-token');
    });
});

it('base64url-encodes the raw RFC 2822 message, decodable back to the original headers and body', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token']),
        'gmail.googleapis.com/*' => Http::response(['id' => 'abc123']),
    ]);

    $email = (new Email)
        ->from('soporteludodex@gmail.com')
        ->to('destinatario@example.com')
        ->subject('Reset password')
        ->text('Click the link to reset your password.');

    (new GmailApiTransport('client-id', 'client-secret', 'refresh-token'))->send($email);

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send') {
            return false;
        }

        $raw = $request['raw'];
        // base64url no lleva "+", "/" ni "=" - si aparecen, no se convirtio bien desde base64 estandar
        expect($raw)->not->toContain('+')->not->toContain('/')->not->toContain('=');

        // ASCII a proposito - el asunto/cuerpo con acentos se codifican como
        // "quoted-printable"/palabras codificadas en las cabeceras MIME, no
        // apareceria el texto literal buscandolo tal cual dentro de lo decodificado
        $decoded = base64_decode(strtr($raw, '-_', '+/'));

        return str_contains($decoded, 'Reset password')
            && str_contains($decoded, 'destinatario@example.com')
            && str_contains($decoded, 'Click the link to reset your password.');
    });
});

it('throws when Google rejects the refresh token', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    (new GmailApiTransport('client-id', 'client-secret', 'expired-token'))->send(fakeEmail());
})->throws(RuntimeException::class, 'renovar el token de acceso');

it('throws when the Gmail send API itself rejects the message', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-access-token']),
        'gmail.googleapis.com/*' => Http::response(['error' => 'insufficient permissions'], 403),
    ]);

    (new GmailApiTransport('client-id', 'client-secret', 'refresh-token'))->send(fakeEmail());
})->throws(RuntimeException::class, 'fallo al enviar el correo');
