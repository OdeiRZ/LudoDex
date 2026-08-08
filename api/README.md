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

`register` y `login` piden un campo `device_name` (etiqueta libre para el
token, pensada para una futura pantalla de "sesiones activas").

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
