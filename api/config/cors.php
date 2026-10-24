<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| Solo el frontend de la app (y sus despliegues de vista previa) puede leer las
| respuestas del API desde un navegador; antes era `*`. El riesgo de `*` era
| bajo (token Bearer, sin cookies: otra web no tiene el token), pero no hay
| motivo para que cualquier web pueda hablar con el API. Localhost queda
| permitido para el desarrollo y los e2e. CORS_ALLOWED_ORIGINS (separados por
| comas) cambia la lista sin tocar código. Las llamadas de servidor a servidor
| (la función de vista previa de Cloudflare, por ejemplo) no pasan por CORS.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://ludodex.pages.dev')),
    ))),

    'allowed_origins_patterns' => [
        '#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#',
        // Despliegues de vista previa de Cloudflare Pages (<hash>.ludodex.pages.dev).
        '#^https://[a-z0-9-]+\.ludodex\.pages\.dev$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['ETag'],

    'max_age' => 7200,

    'supports_credentials' => false,

];
