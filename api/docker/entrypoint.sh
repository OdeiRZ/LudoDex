#!/bin/sh
set -e

php artisan migrate --force

# Cachea la configuración en un solo fichero: sin esto Laravel vuelve a leer y a
# fusionar todos los de config/ en CADA petición. Aquí (no al construir la imagen)
# porque necesita las variables de entorno de Render, que solo existen al arrancar.
# Ningún código llama a env() fuera de config/, así que es seguro. Si por lo que
# sea fallase, se sigue arrancando sin caché en vez de dejar el servicio caído.
php artisan config:cache || echo "config:cache ha fallado: se arranca sin configuración cacheada"

exec "$@"
