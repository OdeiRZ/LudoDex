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

**Por qué Resend y no SMTP directo (ni Gmail ni ningún otro):** probado en
vivo contra producción — Render bloquea las conexiones salientes por SMTP
por completo (confirmado con timeouts idénticos en los puertos 587 y 465
contra `smtp.gmail.com`, con las credenciales correctas), algo habitual en
plataformas cloud para evitar abuso de spam desde cuentas gratuitas. Resend
funciona porque su SDK (`resend/resend-php`, ya en `composer.json`) usa su
API HTTPS, no SMTP, así que nunca tropieza con ese bloqueo.

### Alternativa a Resend sin dominio propio (API de Gmail)

Resend sin dominio verificado solo entrega al dueño de la cuenta — no vale
para usuarios reales. Comprar y verificar un dominio es la vía "normal",
pero `MAIL_MAILER=gmail_api` (`App\Mail\Transport\GmailApiTransport`) es una
alternativa gratuita que sí entrega a cualquier destinatario real, sin
dominio propio: envía como una dirección de Gmail de verdad, por la API REST
de Gmail (HTTPS, `gmail.googleapis.com`) en vez de SMTP — así tampoco
tropieza con el bloqueo de Render de más arriba. Enviar "como" una dirección
`@gmail.com` desde un tercero por SMTP normalmente fracasa la política
DMARC estricta de Gmail (se rechaza o va a spam) salvo que el envío pase de
verdad por los servidores de Google, que es justo lo que hace la API.

La autenticación es OAuth2 (nunca una contraseña, ni siquiera una
"contraseña de aplicación"): un *refresh token* de larga duración que la
propia app usa para renovar un token de acceso en cada envío
(`GmailApiTransport::fetchAccessToken()`). Conseguirlo es un proceso manual,
de una sola vez, en la cuenta de Gmail que va a enviar los correos:

1. Crear un proyecto en [Google Cloud Console](https://console.cloud.google.com/)
   (gratis) con esa cuenta.
2. Habilitar la **Gmail API** para ese proyecto (buscarla en "APIs y
   servicios" → "Biblioteca").
3. Configurar la pantalla de consentimiento OAuth: tipo "Externo", en modo
   "Prueba" — así no hace falta pasar la revisión de Google, solo funciona
   para las cuentas que se añadan como "usuarios de prueba" (añadir la
   propia cuenta que va a enviar los correos).
4. Crear credenciales OAuth 2.0 (tipo "Aplicación de escritorio") — da un
   `client_id` y un `client_secret`. Esos dos valores van directos a
   `GMAIL_CLIENT_ID`/`GMAIL_CLIENT_SECRET`, no son secretos de un solo uso.
5. Conseguir el `refresh_token` (esto sí es un paso manual con el navegador,
   una única vez): visitar una URL de autorización de Google con el scope
   `https://www.googleapis.com/auth/gmail.send`,
   `access_type=offline` y `prompt=consent`, iniciar sesión con la cuenta
   que va a enviar los correos, aceptar, y coger el parámetro `code` de la
   URL de redirección. Cambiarlo por un token con una petición POST a
   `https://oauth2.googleapis.com/token` (`grant_type=authorization_code`)
   — la respuesta trae el `refresh_token`, que va a `GMAIL_REFRESH_TOKEN`.
   **Ojo**: mientras la pantalla de consentimiento esté en modo "Prueba"
   (el caso normal aquí, ver paso 3), Google expira ese refresh token a
   los 7 días — no es de larga duración como en una app en modo
   "Producción". Pasado ese plazo, `fetchAccessToken()` empieza a fallar
   con `invalid_grant` y hay que repetir este paso 5 entero para sacar un
   token nuevo. Pasar a "Producción" evitaría la caducidad, pero exige la
   revisión de seguridad de Google (CASA) por tratarse de un scope
   restringido (`gmail.send`) — no vale la pena para el volumen de esta
   app.
6. Poner `MAIL_MAILER=gmail_api`, `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` con
   esa misma cuenta, y las tres `GMAIL_*` de arriba.

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
`database` (no hay Redis ni *worker* en el plan Free). `MAIL_MAILER=gmail_api`
está configurado en producción (no `resend` — Render bloquea SMTP saliente
por completo, y sin dominio propio verificado Resend solo entregaría a la
dirección de la propia cuenta de Resend; ver "Alternativa a Resend sin
dominio propio" más arriba), junto con `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`
y las tres `GMAIL_CLIENT_ID`/`GMAIL_CLIENT_SECRET`/`GMAIL_REFRESH_TOKEN` —
probado en vivo contra un destinatario real arbitrario, no solo la cuenta
propia. **Ojo con `GMAIL_REFRESH_TOKEN`**: caduca cada 7 días mientras la
pantalla de consentimiento de Google siga en modo "Prueba" (ver el paso 5
de arriba) — si `/api/forgot-password` empieza a fallar en producción sin
ningún cambio de código, revisar la edad del token antes que nada.
`DEEPL_API_KEY` sí está configurada en Render, así que el botón de traducir
funciona igual en producción que en local. `FRONTEND_URL` (la URL pública de
la SPA en Cloudflare Pages) es la base de los enlaces que llevan los emails
de verificación y de solicitud de amistad — sin ella en producción, esos
enlaces apuntarían a `http://localhost:5173` (el valor por defecto de
`config/app.php`). `OWNER_EMAIL` es obligatoria para
`POST /api/games/backfill-translations` (ver más abajo).

`SENTRY_LARAVEL_DSN` (opcional, vacía por defecto — sin ella el SDK no hace
nada): monitorización de errores en producción vía
[Sentry](https://sentry.io) (plan gratuito), cableada en `bootstrap/app.php`
(`Sentry\Laravel\Integration::handles()`) — motivado directamente por lo que
costó diagnosticar el fallo de SMTP de arriba a base de copiar y pegar a
mano la línea de log correcta del panel de Render, varias veces seguidas.
`traces_sample_rate` se deja sin definir a propósito (`config/sentry.php`
cae a `null`, sin tracing de rendimiento) — solo interesan los errores.

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
logout ahí lo revocaría (o cambiar/resetear la contraseña de esa
cuenta — ver CHANGELOG), con lo que el comando dejaría de funcionar
hasta pegar uno nuevo. Se ejecuta siempre desde local (producción no
tiene forma de alcanzar tu máquina), en las dos direcciones: hacia
producción vía el propio `POST /api/games/backfill-translations`, y
hacia local escribiendo directamente en la base de datos (sin pasar por
HTTP, al correr ya en el mismo proceso).

`POST /api/games/backfill-translations` escribe en el catálogo de
juegos compartido, no en nada propio de quien llama, así que un simple
`auth:sanctum` no basta — esta app no tiene ni roles ni admin, y ya hay
más de una cuenta en este despliegue. El controlador compara el email
del usuario autenticado contra `OWNER_EMAIL` (`config('app.owner_email')`,
ver `.env.example`) y responde 403 si no coincide. Sin esa variable
configurada en producción, el endpoint rechaza cualquier llamada,
incluida la tuya propia.

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
| GET    | `/api/email/verify/{id}/{hash}` | No (URL firmada) | Confirma el email y redirige a `{frontend}/verify-email?ok=0/1` — ver "Verificación de email" más abajo |
| POST   | `/api/email/verification-notification` | Sí | Reenvía el email de verificación (no hace nada si ya está verificado; limitado a 6/minuto) |

`register` y `login` piden un campo `device_name` (etiqueta libre para el
token, pensada para una futura pantalla de "sesiones activas"). `PUT
/api/user` acepta un `bgg_username` opcional: si se envía y ha cambiado
respecto al guardado, intenta rellenar `avatar_url` desde esa cuenta de BGG
(`App\Services\Bgg\BggClient::fetchUserAvatar`) de forma *best-effort* — un
fallo ahí (sin token, usuario inexistente, BGG caído) nunca bloquea el resto
del guardado.

### Verificación de email

`/register` deja crear una cuenta con cualquier email, sin comprobar que
su dueño la pidió — mismo hallazgo de auditoría de seguridad que en
MIRA_MarketLens. `register()` sigue logueando al usuario al instante,
igual que siempre — esto es confirmación de email, no una puerta de
acceso, así que no hay middleware `verified` en ninguna ruta.

`User` implementa `Illuminate\Contracts\Auth\MustVerifyEmail` (trait
`Illuminate\Auth\MustVerifyEmail`, sin código propio) y `register()` llama
a `sendEmailVerificationNotification()` tras crear la cuenta. El modelo la
sobreescribe (mismo patrón ya usado para `sendPasswordResetNotification()`)
para mandar `App\Notifications\VerifyEmailNotification` — una subclase de
la notificación de serie de Laravel que solo sobreescribe
`buildMailMessage()`, con el copy en `lang/{es,en}/mail.php` en vez de
hardcodeado, así que sale traducido de verdad según el `Accept-Language`
de quien se registra (ver `SetLocaleFromHeader`).

A diferencia del enlace de restablecer contraseña (token guardado en BD,
apunta directo al frontend), la verificación de email en Laravel usa una
**URL firmada** que el propio framework construye a partir de la ruta
`verification.verify` — por eso el enlace apunta a esta misma API
(`GET /email/verify/{id}/{hash}`), no al frontend: la firma tiene que
comprobarse contra la URL exacta que se firmó.
`EmailVerificationController::verify()` no usa el middleware `signed`
(abortaría con la página de error por defecto de Laravel antes de llegar
al controlador) — comprueba la firma a mano con
`$request->hasValidSignature()` para poder redirigir siempre a
`{frontend}/verify-email?ok=0` o `?ok=1`, tanto si falla como si acierta.

| Método | Ruta                     | Auth | Descripción                              |
|--------|--------------------------|------|-------------------------------------------|
| GET    | `/api/games`             | Sí   | Lista la colección del usuario autenticado |
| POST   | `/api/games`             | Sí   | Crea un juego y lo añade a la colección |
| PUT    | `/api/games/{userGame}`  | Sí   | Actualiza el estado/notas y, si se envían, los datos del juego y sus mecánicas/categorías |
| DELETE | `/api/games/{userGame}`  | Sí   | Quita el juego de la colección |
| DELETE | `/api/games`             | Sí   | Vacía toda la colección del usuario autenticado (deja el catálogo `games` compartido intacto) |
| GET    | `/api/mechanics`         | Sí   | Catálogo de mecánicas (para autocompletar el alta) |
| GET    | `/api/categories`        | Sí   | Catálogo de categorías (para autocompletar el alta) |

`POST /api/games` acepta `mechanics`/`categories` como arrays de nombres:
si el nombre ya existe en el catálogo se reutiliza, si no se crea sobre la
marcha (`firstOrCreate`). Actualizar o borrar la entrada de otro usuario
devuelve 403 (`App\Policies\UserGamePolicy`).

Si el `bgg_id` enviado ya existe en el catálogo compartido (otra cuenta
añadió antes ese mismo juego real, algo perfectamente normal con más de un
usuario), reutiliza esa fila de `games` en vez de intentar crear otra —
`bgg_id` es único en esa tabla desde la primera migración, así que crear una
segunda fila con el mismo valor reventaba con un 500 sin manejar en vez de
responder algo útil (encontrado probando el propio endpoint de backfill de
traducciones con un juego real que ya existía en el catálogo). Si además el
usuario actual ya tenía ese juego en su propia colección, responde `422` con
un mensaje claro en vez de otro 500 (violación del `unique(user_id, game_id)`
de `user_games`). Un juego sin `bgg_id` (alta totalmente manual, sin pasar
por "Rellenar desde BGG") sigue creando siempre su propia fila, igual que
antes.

`PUT /api/games/{userGame}` tenía el mismo punto ciego por el lado de
editar: cambiar el `bgg_id` de un juego ya en la colección al de otro juego
que ya existiera en el catálogo también reventaba con un 500 sin manejar
(mismo `unique` de `games.bgg_id`, encontrado al auditar el resto de
constraints únicos del esquema tras el fallo de arriba). `UpdateUserGameRequest`
ahora rechaza ese cambio con un `422` en vez de dejarlo llegar a la base de
datos.

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
Cada partida lleva `game.base_game_name` (mismo campo que ya expone
`GameResource` para la colección/picker) — si la partida se jugó contra
una expansión, el frontend lo muestra como "Expansión de {nombre}" bajo
el nombre de la propia expansión, en vez de una segunda imagen de
portada junto a la suya (una lista que puede llegar a miles de filas no
se beneficia de duplicar imágenes por fila).
`GET /api/plays/stats` siempre agrega sobre el historial completo, nunca
sobre la página actual: `total_plays` y `total_minutes` suman `quantity`
(BGG agrupa varias partidas del mismo juego el mismo día en una sola fila),
y `total_minutes` solo cuenta las partidas con duración conocida —
`duration_known_plays` es lo que le dice al frontend si debe mostrar "Sin
datos" en vez de un total de 0 engañoso. `top_played` suma las partidas de
una expansión a las de su juego base (`COALESCE(games.base_game_id,
games.id)`, no `games.id` a secas) antes de rankear — BGG nunca registra
una partida contra el juego base cuando en realidad se ha jugado con una
expansión suya, aunque esa expansión no sea jugable sin las reglas/
componentes del propio base (comentado directamente); sin este ajuste, un
juego jugado a menudo repartido entre varias expansiones distintas podía
salir peor rankeado que uno jugado menos veces pero siempre bajo el mismo
`game_id`, cuando en la práctica es "el mismo juego" el que más mesa ha
visto. Cada entrada de `top_played` lleva además un `breakdown` — el mismo
total partido de vuelta por `game_id` concreto (el base y cada expansión
que haya contribuido), ordenado de más a menos partidas, junto al total
agregado en vez de en su lugar (mantenido tras probarlo en vivo contra un
historial real con varias expansiones), pero
solo cuando hay más de una fila contribuyendo — `null` si el total viene
de un único juego, para no repetir en un array de una sola entrada lo que
`count` ya dice. `POST /api/bgg-plays-imports` es incremental a partir del segundo
import: solo pide a BGG las partidas desde
la última ya guardada (con una semana de margen de solapamiento), en vez de
repetir el historial completo cada vez. Esto deja un punto ciego real: una
partida que alguien rellena en BGG con fecha antigua (un backfill de una
jugada de hace meses) cae fuera de esa ventana pase lo que pase — ni
reimportando cien veces ni esperando aparece, porque el filtro de fecha la
descarta antes de siquiera consultarla (reportado directamente). `full:
true` en el body de la petición se salta el filtro incremental por completo
y trae el historial entero, igual que en la primera importación — expuesto
en el frontend como la casilla "Reimportar todo el historial".

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

| Método | Ruta                                | Auth | Descripción                              |
|--------|--------------------------------------|------|-------------------------------------------|
| GET    | `/api/friends`                       | Sí   | Lista los amigos ya aceptados |
| GET    | `/api/friends/search`                | Sí   | Busca a alguien por email o usuario de BGG (limitado a 6/minuto) |
| GET    | `/api/friends/requests`              | Sí   | Solicitudes pendientes, recibidas y enviadas, en una sola respuesta |
| POST   | `/api/friends/requests`              | Sí   | Envía una solicitud de amistad (limitado a 6/minuto) |
| POST   | `/api/friends/requests/{friendship}/accept` | Sí | Acepta una solicitud recibida |
| DELETE | `/api/friends/requests/{friendship}` | Sí   | Rechaza/cancela una solicitud, o deshace una amistad ya aceptada (misma ruta para los tres casos) |
| GET    | `/api/friends/blocks`                | Sí   | Lista a quién ha bloqueado el usuario autenticado |
| POST   | `/api/friends/blocks`                | Sí   | Bloquea a un usuario (limitado a 6/minuto) |
| DELETE | `/api/friends/blocks/{block}`        | Sí   | Desbloquea (solo quien bloqueó puede deshacerlo) |
| GET    | `/api/friends/{friend}/games`        | Sí   | Compara la colección propia con la de un amigo ya aceptado (en común / solo mía / solo suya) |
| GET    | `/api/friends/{friend}/plays`        | Sí   | Historial de partidas de un amigo, paginado |
| GET    | `/api/friends/{friend}/plays/stats`  | Sí   | Estadísticas agregadas de las partidas de un amigo |

`search`/`store` (enviar solicitud) están limitados a 6/minuto a
propósito: son los dos puntos donde alguien podría intentar enumerar
qué emails están registrados probando uno detrás de otro. Ambos, junto
con `POST /api/friends/blocks`, comparten el mismo principio de
`FriendshipService`: nunca distinguir "no existe" de "existe pero no
descubrible/bloqueado" en la respuesta — mismo mensaje para los tres
casos, para no convertir el endpoint en un oráculo. `discoverable`
(booleano en `users`, opt-in, `false` por defecto) es lo que decide si
una cuenta aparece en `search` y si es un destino válido para
`store` — bloquear a alguien (en cualquier dirección) tiene el mismo
efecto que si esa persona no fuera descubrible, con idéntico mensaje.

Enviar una solicitud cuando la otra persona ya te había enviado una
pendiente la acepta automáticamente en vez de crear una fila duplicada
— dos personas añadiéndose casi a la vez es un caso real, no un borde a
rechazar (ver `FriendshipService::sendRequest()` para la condición de
carrera real que esto también protege vía un índice único `pair_key`, no
solo la comprobación en la propia petición). Bloquear borra cualquier
amistad o solicitud pendiente existente entre ambos, en cualquier
dirección.

`GET /api/friends/{friend}/games`, `/plays` y `/plays/stats` devuelven
`404` idéntico tanto si `{friend}` no existe como si existe pero no es
un amigo aceptado (nunca distinguible, mismo principio anti-oráculo de
arriba) — pero `403` (con mensaje propio) si sí sois amigos y esa
persona tiene `share_collection`/`share_plays` desactivado en su
perfil: a diferencia del `404`, aquí no hay nada que ocultar (ya sabes
que sois amigos), así que un código distinto permite al frontend
avisar solo de que esa persona concreta no comparte ese dato, sin
ocultar el resto de su ficha.
