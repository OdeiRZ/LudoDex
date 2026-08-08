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
