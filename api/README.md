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
`database` (no hay Redis ni *worker* en el plan Free).

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

| Método | Ruta                              | Auth | Descripción                       |
|--------|-----------------------------------|------|------------------------------------|
| GET    | `/api/bgg-lookup/games/{bggId}`   | Sí   | Consulta un juego por su id de BGG (limitado a 12/minuto) |

A diferencia de `/api/bgg-imports`, esta consulta es síncrona: BGG's
`/thing` endpoint no tiene el estado `202` de exportación en curso que sí
tiene `/collection`, así que una sola llamada basta. Pensado para el botón
"Rellenar desde BGG" del alta/edición manual de un juego (nombre, imagen,
jugadores, duración, complejidad, mecánicas y categorías en una sola
respuesta).
