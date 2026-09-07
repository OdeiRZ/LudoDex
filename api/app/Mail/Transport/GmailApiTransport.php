<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Sends via the Gmail REST API (HTTPS) instead of SMTP. Render blocks
 * outbound SMTP connections entirely (see api/README.md, "Por qué Resend y
 * no SMTP directo") - confirmed with identical connection timeouts against
 * smtp.gmail.com on ports 587 and 465 with correct credentials. The Gmail
 * API sidesteps that: it's a plain HTTPS POST to gmail.googleapis.com,
 * authenticated with an OAuth2 access token minted from a long-lived
 * refresh token (GMAIL_REFRESH_TOKEN), not a password of any kind.
 */
class GmailApiTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $accessToken = $this->fetchAccessToken();

        // Gmail expects the whole RFC 2822 message (headers + body) as a
        // single base64url string - "raw" is the only field it needs.
        $raw = strtr(base64_encode($message->toString()), '+/', '-_');
        $raw = rtrim($raw, '=');

        $response = Http::withToken($accessToken)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => $raw,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Gmail API: fallo al enviar el correo - '.$response->body());
        }
    }

    private function fetchAccessToken(): string
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Gmail API: fallo al renovar el token de acceso - '.$response->body());
        }

        return $response->json('access_token');
    }

    public function __toString(): string
    {
        return 'gmail_api';
    }
}
