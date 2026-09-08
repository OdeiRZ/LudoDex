<?php

return [

    // Subcopy under any mail with an action button - generic across
    // notifications, not just password reset, so it lives at the top level.
    'trouble' => "If the button doesn't work, copy and paste this URL into your browser:",

    'reset_password' => [
        'subject' => 'Reset your LudoDex password',
        'greeting' => 'Hello!',
        'intro' => 'You are receiving this email because we received a password reset request for your LudoDex account.',
        'action' => 'Reset password',
        'expire' => 'This link will expire in :count minutes.',
        'outro' => "If you didn't request a password reset, no further action is required: your current password is still valid.",
        'salutation' => "Best,\nThe LudoDex team",
    ],

    'verify_email' => [
        'subject' => 'Verify your LudoDex email',
        'greeting' => 'Hello!',
        'intro' => 'Thanks for signing up to LudoDex. Confirm that this is your email address.',
        'action' => 'Verify email',
        'outro' => "If you didn't create this account, no further action is required.",
        'salutation' => "Best,\nThe LudoDex team",
    ],

    'friend_request' => [
        'subject' => ':name sent you a friend request',
        'greeting' => 'Hello!',
        'intro' => ':name wants to be your friend on LudoDex.',
        'action' => 'View request',
        'outro' => 'You can accept or decline it from your friends page.',
        'salutation' => "Best,\nThe LudoDex team",
    ],

];
