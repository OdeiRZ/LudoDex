<?php

return [

    // Subcopy under any mail with an action button - generic across
    // notifications, not just password reset, so it lives at the top level.
    'trouble' => 'Si el botón no funciona, copia y pega esta URL en tu navegador:',

    'reset_password' => [
        'subject' => 'Restablece tu contraseña de LudoDex',
        'greeting' => '¡Hola!',
        'intro' => 'Recibes este email porque hemos recibido una solicitud para restablecer la contraseña de tu cuenta de LudoDex.',
        'action' => 'Restablecer contraseña',
        'expire' => 'Este enlace caduca dentro de :count minutos.',
        'outro' => 'Si no has solicitado un cambio de contraseña, no tienes que hacer nada más: tu contraseña actual sigue siendo válida.',
        'salutation' => "Un saludo,\nEl equipo de LudoDex",
    ],

    'verify_email' => [
        'subject' => 'Verifica tu email de LudoDex',
        'greeting' => '¡Hola!',
        'intro' => 'Gracias por registrarte en LudoDex. Confirma que esta es tu dirección de email.',
        'action' => 'Verificar email',
        'outro' => 'Si no has creado esta cuenta, no es necesario que hagas nada.',
        'salutation' => "Un saludo,\nEl equipo de LudoDex",
    ],

    'friend_request' => [
        'subject' => ':name te ha enviado una solicitud de amistad',
        'greeting' => '¡Hola!',
        'intro' => ':name quiere ser tu amigo en LudoDex.',
        'action' => 'Ver solicitud',
        'outro' => 'Puedes aceptarla o rechazarla desde tu perfil de amigos.',
        'salutation' => "Un saludo,\nEl equipo de LudoDex",
    ],

];
