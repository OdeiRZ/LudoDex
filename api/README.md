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
email real por ahora. `DEEPL_API_KEY` sí está configurada en Render, así
que el botón de traducir funciona igual en producción que en local.

## Sincronizar traducciones entre local y producción

```bash
php artisan translations:sync            # copia en ambas direcciones
php artisan translations:sync --dry-run  # solo informa de lo que cambiaría
```

Copia `description_es` entre esta base de datos local y producción, en
ambas direcciones, sin sobrescribir nunca una traducción ya existente en
ninguno de los dos lados (misma regla de "solo rellena huecos" que ya
aplica `GameTranslationBackfillController` del lado de producción) —
así no hace falta volver a gastar cuota de DeepL traduciendo el mismo
juego dos veces solo porque se probó primero en un sitio y luego en el
otro. Sustituye al proceso manual que había antes (sacar el token de
sesión de una pestaña del navegador ya logueada en producción y llamar
al endpoint de backfill a mano).

Necesita `PROD_API_URL` y `PROD_API_TOKEN` en `.env` (ver
`.env.example`) — el token es un token Sanctum normal de tu propia
cuenta; al ser el mismo que usa tu sesión activa en el navegador, un
logout ahí lo revocaría, con lo que el comando dejaría de funcionar
hasta pegar uno nuevo. Se ejecuta siempre desde local (producción no
tiene forma de alcanzar tu máquina), en las dos direcciones: hacia
producción vía el propio `POST /api/games/backfill-translations`, y
hacia local escribiendo directamente en la base de datos (sin pasar por
HTTP, al correr ya en el mismo proceso).

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
| POST   | `/api/register` | No   | Crea el usuario y devuelve `{ user, token }` (limitado a 6 intentos/minuto) |
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
| GET    | `/api/bgg-imports/{id}`     | Sí   | Consulta el estado; si sigue `pending`, reintenta contra BGG en la propia petición (limitado a 30/minuto) |

Sin *worker* en segundo plano (ver README raíz): la exportación de
colecciones de BGG es asíncrona (responde `202` mientras se genera), así que
`GET /api/bgg-imports/{id}` no se limita a leer el estado guardado — cada
llamada reintenta la petición a BGG mientras siga `pending`. El frontend hace
*polling* contra este endpoint cada 3s hasta `completed` o `failed` (~20
peticiones/minuto en uso normal); el límite de 30/minuto deja margen sobre
eso mientras sigue acotando un *polling* descontrolado.

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

| Método | Ruta                      | Auth | Descripción                       |
|--------|---------------------------|------|--------------------------------------------|
| GET    | `/api/plays`              | Sí   | Lista el historial de partidas del usuario, paginado (20/página), más reciente primero |
| GET    | `/api/plays/stats`        | Sí   | Estadísticas agregadas sobre todo el historial: partidas jugadas, juegos distintos, tiempo total y top 3 de más jugados |
| POST   | `/api/bgg-plays-imports`  | Sí   | Importa el historial de partidas desde BGG (limitado a 6/minuto) |

`GET /api/plays` acepta `?search=` para filtrar por el nombre del juego
jugado (case-insensitive, resuelto en el propio backend ya que la lista
está paginada — un filtro en el cliente solo vería la página ya cargada).
`GET /api/plays/stats` siempre agrega sobre el historial completo, nunca
sobre la página actual: `total_plays` y `total_minutes` suman `quantity`
(BGG agrupa varias partidas del mismo juego el mismo día en una sola fila),
y `total_minutes` solo cuenta las partidas con duración conocida —
`duration_known_plays` es lo que le dice al frontend si debe mostrar "Sin
datos" en vez de un total de 0 engañoso. `POST /api/bgg-plays-imports` es
incremental a partir del segundo import: solo pide a BGG las partidas desde
la última ya guardada (con una semana de margen de solapamiento), en vez de
repetir el historial completo cada vez.

`BggClient::fetchPlays()` pagina contra `/plays` (100 partidas por página) y
BGG limita la velocidad de peticiones consecutivas — reproducido
directamente contra una cuenta real de 7250 partidas (73 páginas): pedirlas
todas seguidas, sin ninguna pausa, provocó un 429 a partir de la página 15,
y el código entonces trataba cualquier respuesta que no fuera 2xx como error
fatal, descartando también las páginas ya descargadas con éxito — así se
reportó el fallo de "byfed" directamente.

La pausa entre páginas empieza en 1s pero **se duplica** (hasta un tope de
10s) en cuanto una sola página choca con un 429 — no solo para esa página,
para el resto de la importación — y esa misma página se reintenta con
espera creciente (0s, 3s, 6s, 12s, 24s, 40s) antes de rendirse del todo.
Una primera versión de este arreglo (pausa fija de 1s, solo 3 reintentos)
bastó para importar la cuenta de 7250 partidas completa una vez
(verificado directamente, ~140s en total) pero falló en un segundo intento
poco después contra la misma cuenta — el margen de BGG no se había
recuperado del todo entre ambas pruebas, así que un ritmo fijo no bastaba;
de ahí que ahora se adapte sobre la marcha (`$pageDelayMicroseconds` se
dobla en el propio bucle de `fetchPlays()`) en vez de asumir que el primer
resultado se repetirá siempre igual.

Con reintentos y pausas, una cuenta grande bajo rate-limit sostenido puede
tardar varios minutos en total — de hecho, un segundo fallo real (reportado
localmente tras este mismo arreglo) no era cosa de BGG en absoluto: era
PHP matando la petición a los 60 segundos (`max_execution_time` por
defecto), a mitad de una de las propias esperas de reintento. Por eso
`BggPlaysImportService::import()` llama a `set_time_limit(300)` al
empezar — una anulación por código, no un cambio de `php.ini`, así que
vale igual en cualquier entorno sea cual sea su configuración por defecto.
Con este arreglo, la cuenta de 7250 partidas importa de principio a fin
verificado tanto en local como en producción.
