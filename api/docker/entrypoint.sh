#!/bin/sh
set -e

php artisan migrate --force

# Cachea configuración, eventos, rutas y vistas: sin esto Laravel vuelve a leer y a
# fusionar todos los ficheros de config/, a registrar las rutas y a compilar las
# plantillas en CADA petición (en la CPU mínima del plan Free se nota). Aquí (no al
# construir la imagen) porque la configuración necesita las variables de entorno de
# Render, que solo existen al arrancar. Ningún código llama a env() fuera de
# config/, así que es seguro. Si por lo que sea fallase, se sigue arrancando sin
# cachés en vez de dejar el servicio caído.
php artisan optimize || echo "optimize ha fallado: se arranca sin cachés"

exec "$@"
