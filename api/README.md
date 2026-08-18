# LudoDex API

API REST en Laravel 12 para [LudoDex](../README.md). Autenticación por token
(Sanctum Personal Access Tokens) — ver la nota de arquitectura en el README
raíz para el porqué.

## Instalación

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # desarrollo: SQLite; producción: Postgres (Neon)
php artisan migrate
php artisan serve
```

Para la importación desde BGG hace falta además `BGG_APPLICATION_TOKEN`: BGG
dejó de ofrecer su API XML sin autenticación y ahora exige registrar la
aplicación y usar un token de aplicación como Bearer token en cada petición.
Se registra en <https://boardgamegeek.com/using_the_xml_api>. Sin ese token,
cualquier importación falla inmediatamente con un mensaje explicando por qué
(no con un 401 en crudo).

Para traducir al español la descripción de un juego (botón "Traducir al
español" en el modal de detalles, tanto en Colección como en "¿A qué
jugamos?") hace falta `DEEPL_API_KEY`: una clave del plan gratuito de
[DeepL](https://www.deepl.com/en/signup?product=api_free) (identificable por
el sufijo `:fx`, que usa el endpoint `api-free.deepl.com`, no el de pago).
Sin esa clave configurada la app sigue funcionando con normalidad: la
traducción simplemente no se intenta y se muestra el texto original en
inglés con una etiqueta "EN". La traducción se guarda en `description_es`
una sola vez por juego (no por usuario ni por consulta), ya que `games` es
un catálogo compartido entre toda la colección.

Para que el email de recuperación de contraseña (`/api/forgot-password`) se
envíe de verdad hace falta configurar un mailer real. Por defecto
`MAIL_MAILER=log` escribe el email completo en `storage/logs/laravel.log` en
vez de enviarlo — suficiente para desarrollo local. Para usar
[Resend](https://resend.com) (ya soportado de forma nativa en Laravel 12, y
cuyo plan gratuito — 3000 emails/mes — cubre de sobra el volumen de esta
app): registrarse, crear una API key y poner `MAIL_MAILER=resend` y
`RESEND_API_KEY=<key>` en `.env`. Sin verificar un dominio propio en Resend,
solo se puede enviar desde `onboarding@resend.dev` a la dirección de email de
la propia cuenta de Resend — vale para probar el flujo, no para usuarios
reales de la app (ver README raíz, sección "Requisitos externos"). El
contenido del email vive en `App\Notifications\ResetPasswordNotification` y
`lang/{es,en}/mail.php`, no en el texto genérico por defecto de Laravel.

## Despliegue

En producción ([ludodex-api.onrender.com](https://ludodex-api.onrender.com)):
Render construye `Dockerfile` (root directory `api`, Docker build context el
repo raíz) y lo despliega en el plan Free. Variables de entorno necesarias:
`APP_KEY`, `APP_URL`, `DB_CONNECTION=pgsql` y `DB_HOST`/`DB_PORT`/
`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`/`DB_SSLMODE=require` con los datos
de Neon. **Usar el host directo de Neon, no el "pooled" (sin el sufijo
`-pooler`)**: con el pooler (PgBouncer en modo transacción) las migraciones
fallan de forma intermitente con `SQLSTATE[25P02]` en vez de mostrar el error
real — ver CHANGELOG. `SESSION_DRIVER`/`CACHE_STORE`/`QUEUE_CONNECTION` van a
`database` (no hay Redis ni *worker* en el plan Free). `MAIL_MAILER`/
`RESEND_API_KEY` no están configuradas todavía en producción (pendiente de
un dominio propio que verificar en Resend — ver "Instalación" más arriba),
así que ahí el mailer cae en `log` y `/api/forgot-password` no envía ningún
email real por ahora. `DEEPL_API_KEY` tampoco está configurada todavía en
Render — el botón de traducir sigue ahí, simplemente no traduce nada hasta
que se añada.

## Testing

```bash
php artisan test
vendor/bin/pint --test        # estilo de código (Laravel Pint)
vendor/bin/phpstan analyse    # análisis estático (Larastan, nivel 5)
```

La suite usa Pest y `RefreshDatabase` (SQLite en memoria durante los tests).

## Endpoints actuales

| Método | Ruta            | Auth | Descripción                              |
|--------|-----------------|------|-------------------------------------------|
| POST   | `/api/register` | No   | Crea el usuario y devuelve `{ user, token }` |
| POST   | `/api/login`    | No   | Valida credenciales, devuelve `{ user, token }` (limitado a 6 intentos/minuto) |
| POST   | `/api/logout`   | Sí   | Revoca el token con el que se autenticó la petición |
| GET    | `/api/user`     | Sí   | Devuelve el usuario autenticado |
| PUT    | `/api/user`     | Sí   | Actualiza nombre/email/usuario de BGG |
| PUT    | `/api/user/password` | Sí | Cambia la contraseña (exige `current_password`; limitado a 6/minuto) |
| POST   | `/api/forgot-password` | No | Envía el email de recuperación de contraseña (limitado a 6/minuto) |
| POST   | `/api/reset-password` | No | Cambia la contraseña dado un `token` y `email` válidos (limitado a 6/minuto) |

`register` y `login` piden un campo `device_name` (etiqueta libre para el
token, pensada para una futura pantalla de "sesiones activas"). `PUT
/api/user` acepta un `bgg_username` opcional: si se envía y ha cambiado
respecto al guardado, intenta rellenar `avatar_url` desde esa cuenta de BGG
(`App\Services\Bgg\BggClient::fetchUserAvatar`) de forma *best-effort* — un
fallo ahí (sin token, usuario inexistente, BGG caído) nunca bloquea el resto
del guardado.

| Método | Ruta                     | Auth | Descripción                              |
|--------|--------------------------|------|-------------------------------------------|
| GET    | `/api/games`             | Sí   | Lista la colección del usuario autenticado |
| POST   | `/api/games`             | Sí   | Crea un juego y lo añade a la colección |
| PUT    | `/api/games/{userGame}`  | Sí   | Actualiza el estado/notas y, si se envían, los datos del juego y sus mecánicas/categorías |
| DELETE | `/api/games/{userGame}`  | Sí   | Quita el juego de la colección |
| GET    | `/api/mechanics`         | Sí   | Catálogo de mecánicas (para autocompletar el alta) |
| GET    | `/api/categories`        | Sí   | Catálogo de categorías (para autocompletar el alta) |

`POST /api/games` acepta `mechanics`/`categories` como arrays de nombres:
si el nombre ya existe en el catálogo se reutiliza, si no se crea sobre la
marcha (`firstOrCreate`). Actualizar o borrar la entrada de otro usuario
devuelve 403 (`App\Policies\UserGamePolicy`).

| Método | Ruta                                      | Auth | Descripción                       |
|--------|-------------------------------------------|------|------------------------------------|
| POST   | `/api/games/{game}/translate-description` | Sí   | Traduce la descripción del juego al español (limitado a 20/minuto) |

Idempotente: si el juego ya tiene `description_es`, la devuelve tal cual sin
volver a llamar a DeepL (`games` es un catálogo compartido, así que un mismo
juego solo hace falta traducirlo una vez en total, no una vez por usuario).
Si no hay `description` que traducir, si `DEEPL_API_KEY` no está configurada,
o si DeepL falla por cualquier motivo (caído, sin cuota, timeout), responde
`200` igualmente con `description_es: null` en vez de un error — el frontend
ya sabe mostrar el texto original en inglés en ese caso.

| Método | Ruta                        | Auth | Descripción                              |
|--------|-----------------------------|------|-------------------------------------------|
| POST   | `/api/bgg-imports`          | Sí   | Inicia una importación desde BGG (limitado a 6/minuto) |
| GET    | `/api/bgg-imports/{id}`     | Sí   | Consulta el estado; si sigue `pending`, reintenta contra BGG en la propia petición |

Sin *worker* en segundo plano (ver README raíz): la exportación de
colecciones de BGG es asíncrona (responde `202` mientras se genera), así que
`GET /api/bgg-imports/{id}` no se limita a leer el estado guardado — cada
llamada reintenta la petición a BGG mientras siga `pending`. El frontend hace
*polling* contra este endpoint cada pocos segundos hasta `completed` o
`failed`.

| Método | Ruta                     | Auth | Descripción                              |
|--------|--------------------------|------|--------------------------------------------|
| POST   | `/api/bgg-imports/csv`   | Sí   | Importa la colección desde el CSV que exporta BGG (limitado a 6/minuto) |

A diferencia de `/api/bgg-imports`, no depende de `BGG_APPLICATION_TOKEN`: el
CSV es una exportación de la propia sesión del usuario en BGG, no una llamada
a la API. Todo el fichero se procesa en la misma petición (sin estado
`pending` ni *polling*): mecánicas, categorías, imagen y duración no están en
este export, y las expansiones se omiten porque el fichero no tiene el
enlace expansión → juego base que sí trae la API XML.

| Método | Ruta                              | Auth | Descripción                       |
|--------|-----------------------------------|------|------------------------------------|
| GET    | `/api/bgg-lookup/games/{bggId}`   | Sí   | Consulta un juego por su id de BGG (limitado a 12/minuto) |

A diferencia de `/api/bgg-imports`, esta consulta es síncrona: BGG's
`/thing` endpoint no tiene el estado `202` de exportación en curso que sí
tiene `/collection`, así que una sola llamada basta. Pensado para el botón
"Rellenar desde BGG" del alta/edición manual de un juego (nombre, imagen,
jugadores, duración, complejidad, mecánicas y categorías en una sola
respuesta).
