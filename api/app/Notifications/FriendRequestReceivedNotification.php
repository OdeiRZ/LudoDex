<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Unlike ResetPasswordNotification/VerifyEmailNotification, there's no
 * Laravel built-in for this - it's built from scratch (via()/toMail()),
 * not by overriding a parent's buildMailMessage(). The published mail
 * template/logo under resources/views/vendor/mail still applies to any
 * MailMessage automatically, so nothing extra is needed for branding.
 */
class FriendRequestReceivedNotification extends Notification
{
    public function __construct(private readonly User $requester) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Same pattern as AppServiceProvider::boot()'s ResetPassword::createUrlUsing() -
        // points at the frontend directly, not a server-rendered route.
        $url = rtrim((string) config('app.frontend_url'), '/').'/friends';

        return (new MailMessage)
            ->subject(__('mail.friend_request.subject', ['name' => $this->requester->name]))
            ->greeting(__('mail.friend_request.greeting'))
            ->line(__('mail.friend_request.intro', ['name' => $this->requester->name]))
            ->action(__('mail.friend_request.action'), $url)
            ->line(__('mail.friend_request.outro'))
            ->salutation(__('mail.friend_request.salutation'));
    }
}
