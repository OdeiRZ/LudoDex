<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail
{
    /**
     * Laravel's own VerifyEmail notification builds a generic, unbranded
     * mail ("Laravel", English-only). This overrides just the message
     * content - the URL building (verificationUrl(), a Laravel-signed URL
     * pointing at this same API's own `verification.verify` route) is
     * inherited unchanged, same split as ResetPasswordNotification.
     */
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.verify_email.subject'))
            ->greeting(__('mail.verify_email.greeting'))
            ->line(__('mail.verify_email.intro'))
            ->action(__('mail.verify_email.action'), $url)
            ->line(__('mail.verify_email.outro'))
            ->salutation(__('mail.verify_email.salutation'));
    }
}
