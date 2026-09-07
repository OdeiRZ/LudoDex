<?php

// No 'failed'/'password'/'throttle' here on purpose - Laravel's own
// built-in English strings already cover those framework-native keys;
// this file only needs to exist for messages this app adds itself, which
// have no English source anywhere else.
return [

    'verification' => [
        'already_verified' => 'Your email is already verified.',
        'resent' => "We've sent you a new verification link.",
    ],

];
