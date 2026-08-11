# Changelog

Todos los cambios notables de este proyecto se documentan en este archivo.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/), y este
proyecto usa [Versionado Semántico](https://semver.org/lang/es/).

## [Unreleased]

### Añadido

- Selector de idioma (español/inglés), visible en la cabecera tanto antes
  de iniciar sesión como después (junto al interruptor de tema), no
  escondido en los ajustes de perfil: alguien que no lea español necesita
  poder cambiarlo antes de poder entender el propio formulario de login.
  El frontend usa `vue-i18n`, con la elección guardada en `localStorage`
  igual que el tema. La API también responde en el idioma elegido: un
  nuevo middleware (`SetLocaleFromHeader`) lee la cabecera
  `Accept-Language` que ahora manda el cliente axios y ajusta el locale de
  Laravel en cada petición, así que los mensajes de validación (email
  duplicado, contraseña incorrecta, etc.) y los errores propios de la
  integración con BGG (token sin configurar, usuario no encontrado…)
  salen en el idioma correcto en vez de siempre en español.
- Botón de editar (icono de tuerca) en cada resultado de "¿A qué jugamos?":
  antes había que volver a "Tu colección" para corregir los datos de un
  juego que aparecía mal filtrado (jugadores, duración, modo…), rompiendo
  el flujo de "elige y juega" que es el propósito de esa página. Lleva
  directamente al formulario de edición de ese juego.
- Enlace "← Volver" en las pantallas de añadir/editar juego. La de editar
  es ahora accesible desde dos sitios (la colección y el atajo del
  selector de arriba), así que "volver" recuerda de cuál de los dos vino
  (vía un parámetro en la URL, no en el historial del navegador, para que
  funcione igual aunque se recargue la página) tanto al pulsar el enlace
  como al guardar los cambios — antes, guardar siempre mandaba a la
  colección aunque se hubiera entrado desde el selector.
- `LoadingSpinner.vue`: un dado (con sus 5 pips) girando sobre sí mismo, en
  vez de solo el texto "Cargando…"/"Cargando tu colección…" que había
  hasta ahora. Sustituye a ese texto suelto en la colección, el selector y
  el formulario de edición; junto al aviso de conexión con BGG en la
  importación; y junto al aviso de "puede tardar unos segundos" (arranque
  en frío de Render) de login, registro, perfil y los formularios de
  alta/edición de juego — este último grupo se quedó sin el dado en el
  primer pase porque solo se tocaron las pantallas de juegos, aunque el
  mismo arranque en frío afecta igual a login/registro/perfil. Respeta
  `prefers-reduced-motion` (sin animación si el sistema pide reducir el
  movimiento).
- El filtro de "Minutos disponibles" en "¿A qué jugamos?" pasa de un campo
  numérico libre a un radio de franjas (Cualquiera / hasta 30 min / 1h /
  1h30 / 2h), igual que ya se hace con "Modo": nadie piensa en minutos
  exactos, y evita abrir el teclado numérico en móvil para un valor suelto.
  El filtro de "Estructura" (Cualquiera/Campaña/Arcade) se simplifica a un
  único checkbox "Solo modo campaña": excluir explícitamente los juegos
  con campaña era un caso mucho más raro que buscarlos, así que la tercera
  opción sobraba.
- Recuperación de contraseña desde la pantalla de inicio de sesión, para
  cuando no se recuerda la contraseña: enlace "¿Olvidaste tu contraseña?"
  bajo el campo de contraseña → formulario para pedir el enlace por email
  → formulario para elegir una nueva contraseña. Usa el sistema de reseteo
  ya incorporado en Laravel (`Password` broker, tabla
  `password_reset_tokens`), con un `AppServiceProvider::boot()` que
  redirige el enlace del email a la SPA (`/reset-password?token=…`) en
  vez de a una vista renderizada por el servidor, ya que la API no tiene
  ninguna. En local, sin mailer configurado, el enlace se escribe en
  `storage/logs/laravel.log` (`MAIL_MAILER=log`); en producción queda
  pendiente configurar `RESEND_API_KEY` y `MAIL_MAILER=resend` en las
  variables de entorno de Render (Resend ya viene soportado de forma
  nativa en Laravel 12, y su plan gratuito — 3000 emails/mes — cubre de
  sobra el volumen de esta app). Probado con un envío real a través de
  Resend antes de darlo por cerrado.
- El email de recuperación de contraseña deja de usar la plantilla
  genérica de Laravel (logo y pie de "Laravel", contenido solo en inglés)
  y pasa a tener marca e idioma propios: cabecera y botón en el teal de
  LudoDex en vez de negro, sin logo de Laravel, asunto/saludo/texto
  traducidos a español e inglés según el idioma de quien lo solicita
  (`App\Notifications\ResetPasswordNotification`, con sus propias claves
  en `lang/{es,en}/mail.php`), y el enlace de la cabecera apunta a la SPA
  en vez de a la URL de la API.
- Botón para mostrar/ocultar la contraseña (icono de ojo) en todos los
  campos de contraseña de la app (login, registro, cambio de contraseña
  en el perfil y elegir contraseña nueva tras un reset): evita el error
  de escribir mal la contraseña sin darse cuenta, sobre todo al repetirla.
  Componente reutilizable `PasswordInput.vue`.
- Página 404 (`NotFoundView.vue`) para cualquier ruta que no exista: antes
  el router no tenía ninguna ruta de repuesto y Vue Router simplemente
  dejaba la pantalla en blanco. El botón de vuelta lleva a la colección
  si hay sesión iniciada, o al login si no.
- `<meta name="description">` en `index.html`, de cara a SEO y a que el
  proyecto se entienda mejor como enlace suelto (portfolio, redes).
- Integración continua con GitHub Actions (`.github/workflows/ci.yml`):
  en cada push/PR a `main` corren, en paralelo, los mismos *quality gates*
  que ya se ejecutaban solo a mano — backend (Pint, Larastan, Pest) y
  frontend (ESLint, Vitest, `vue-tsc`, build) — más el badge de estado en
  el README. Junto con Dependabot (`composer`, `npm` y las propias
  Actions), que abrirá PRs automáticos cuando haya actualizaciones,
  incluidas las de seguridad, en vez de depender de acordarse de auditar
  las dependencias a mano de vez en cuando.
- Importación de la colección desde el CSV que exporta BoardGameGeek
  (pestaña "Desde CSV" en Importar BGG, junto a la ya existente "Por
  usuario"), como alternativa mientras la aprobación de la aplicación en
  BGG para el token de la API XML sigue pendiente: ese fichero es una
  exportación directa de la sesión del propio usuario, así que no depende
  de ningún token. A diferencia de la importación por usuario, procesa el
  fichero entero de forma síncrona (sin estado pendiente/polling) y
  devuelve el resultado directamente. El modo de juego y el número de
  jugadores se leen del comentario privado de cada fila (el usuario anota
  ahí, por ejemplo, "Cooperativo - 1/4" o "Solitario") en vez de las
  columnas oficiales de jugadores mínimos/máximos de BGG, porque reflejan
  mejor cómo se juega realmente cada partida. Las expansiones se omiten
  por ahora (el CSV no tiene el enlace expansión→juego base que sí trae la
  API XML), igual que las filas sin "Lo tengo" ni "Lo quiero" marcado.
- Contador de juegos junto al título en "Tu colección" y en "¿A qué
  jugamos?": con una colección de cientos de juegos, tener claro cuántos
  hay en total (o cuántos cumplen los filtros activos en el selector) sin
  tener que contar tarjetas a ojo. En el selector el número se recalcula
  con cada cambio de filtro, ya que refleja los resultados filtrados, no
  el total de la colección.
- Buscador por nombre en "Tu colección": con cientos de juegos importados
  desde BGG, encontrar uno concreto a base de bajar la página dejó de ser
  práctico. Filtra en el cliente sobre la colección ya cargada (igual que
  los filtros del selector), sin llamada adicional a la API; el contador
  del título pasa a reflejar el resultado filtrado, no el total.
- Botón de densidad ("vista compacta"/"vista cómoda") junto al buscador de
  "Tu colección", persistido en `localStorage` igual que el tema: reduce
  el tamaño de la miniatura, el espaciado de la tarjeta y oculta las
  mecánicas para ver más juegos por pantalla sin necesidad de dos vistas
  (grid/lista) separadas — mismo marcado, solo cambia una clase CSS. El
  mismo botón se añade también sobre los resultados de "¿A qué jugamos?"
  (ahí oculta el modo/campaña en vez de las mecánicas); la lógica se
  extrajo a un composable (`useCollectionDensity`) y a un componente
  (`DensityToggle`) compartidos por las dos vistas, con la preferencia
  común a ambas.

### Corregido

- El desplegable de sugerencias de `TagInput` dejaba sus botones dentro
  del orden de tabulación: al pulsar Tab desde el campo de texto, el foco
  podía aterrizar en un botón que el propio evento `blur` acababa de
  eliminar del DOM (al cerrarse la lista), perdiendo el foco de vuelta a
  la página. Ahora esos botones llevan `tabindex="-1"`; seguir eligiendo
  una sugerencia sin ratón sigue funcionando escribiendo su texto y
  pulsando Intro.
- "Recuperar contraseña" mostraba el error en crudo (p. ej.
  `Error: Request failed with status code 500`) cuando el fallo no era de
  validación, en vez de un mensaje traducido como el resto de la app.
- El campo de complejidad (peso) al editar un juego tenía `step="0.1"`,
  que en un input numérico HTML solo admite un decimal: guardar un valor
  con dos decimales (p. ej. 3.64, el formato habitual del dato de BGG, y
  el mismo que ya guarda el importador CSV) lo rechazaba como inválido
  aunque el backend siempre lo había aceptado sin problema. Ahora el paso
  es `0.01`.

- En "¿A qué jugamos?": el filtro "Solo modo campaña" sonaba ambiguo junto
  al botón "Solo" de jugador único de la misma fila (¿"solo" de "solitario"
  o "solo" de "únicamente"?). Pasa a llamarse "Con modo campaña". El campo
  de "Jugadores" también reduce su ancho: no necesita espacio para más de
  dos dígitos.
- El aviso de arranque en frío junto al dado de carga ("Puede tardar unos
  segundos si el servidor estaba inactivo.") se acorta a "Servidor
  inactivo: puede tardar unos segundos.".
- El mensaje de validación de email duplicado (registro y edición de
  perfil) decía "Ese valor de email ya está en uso.", traducción literal
  del paquete de idioma español de Laravel que suena forzada en
  castellano. Ahora dice "El email ya está en uso.".
- El filtro de "Minutos disponibles" en "¿A qué jugamos?" no excluía nunca
  ningún juego: solo comparaba contra `max_playtime_minutes`, y la mayoría
  de juegos (incluidos varios importados directamente de BGG) solo tienen
  relleno un único valor de duración, no un min/max real, así que ese
  campo quedaba a `null` y la condición no se cumplía jamás. Ahora usa
  cualquiera de los dos valores que exista (prefiriendo el mínimo) como
  referencia. Detectado al probarlo en vivo tras un aviso del usuario.
- El icono del interruptor de tema mostraba el modo actual (luna en modo
  oscuro, sol en modo claro) en vez del modo al que cambiarías al pulsarlo,
  al revés de lo que ya decía su propio `aria-label`/`title` ("cambiar a
  modo claro" se mostraba junto a una luna, no un sol). Detectado al
  probarlo tras el 0.1.0. Ahora el icono coincide con la acción: sol en
  modo oscuro (pulsar para aclarar), luna en modo claro (pulsar para
  oscurecer).

## [0.1.0] - 2026-08-09

### Añadido

- Cimientos del proyecto: repo con `api/` (Laravel 12 + Sanctum) y `web/`
  (Vue 3 + Vite + TypeScript + Pinia + Vue Router), cada uno con su propio
  README.
- Autenticación completa de punta a punta: registro, login, logout y
  restauración de sesión al recargar la página, con token Bearer (Sanctum
  Personal Access Tokens) guardado en `localStorage` — ver la nota de
  arquitectura en el README raíz sobre por qué token en vez de cookie de
  sesión.
- Suite de tests Pest en la API (registro, login, logout, rutas protegidas) y
  Pint + Larastan (nivel 5) en CI local desde el primer commit, para no
  repetir la deuda de tenerlos que añadir a posteriori como en otro proyecto
  del portfolio.
- ESLint + Prettier + Vitest configurados en el frontend desde el scaffolding
  inicial (vía `create-vue`).
- Inventario manual de juegos: modelos `games` (con `base_game_id` para
  expansiones), `mechanics` y `categories` (catálogo compartido, reutilizado
  automáticamente por nombre en vez de duplicarse en cada alta), y
  `user_games` como tabla intermedia con estado (`owned`/`wishlist`) y notas.
  Endpoints `GET/POST /api/games`, `PUT/DELETE /api/games/{userGame}`,
  `GET /api/mechanics`, `GET /api/categories`, con autorización por
  `UserGamePolicy` (nadie puede editar o borrar la entrada de otro usuario).
  Frontend: pantalla de colección con tarjetas por juego, formulario de alta
  con selector de mecánicas/categorías como "tags" con autocompletado y alta
  de nuevas sobre la marcha (`TagInput.vue`), y borrado desde la propia
  tarjeta. Verificado de punta a punta en el navegador: alta con mecánicas y
  categorías nuevas, persistencia tras recargar, y borrado.
- Importación de colección desde BoardGameGeek: `App\Services\Bgg\BggClient`
  (llamadas a `xmlapi2/collection` y `xmlapi2/thing`, parseo con SimpleXML) y
  `BggImportService` (upsert de juegos por `bgg_id`, enlace de expansiones a
  su juego base vía el link `inbound` de BGG, inferencia de
  cooperativo/campaña a partir de mecánicas y categorías). Tabla
  `bgg_imports` para trackear el estado (`pending`/`completed`/`failed`) sin
  worker en background: `GET /api/bgg-imports/{id}` reintenta contra BGG en
  la propia petición mientras siga `pending`, y el frontend hace *polling*
  cada 3s (`ImportBggView.vue`). Tests con `Http::fake()` y XML de ejemplo
  realista (colección + expansión + enlace inbound), sin llamadas reales a
  BGG.
- Selector "¿A qué jugamos?" (`PickerView.vue`): filtra la colección propia
  (solo juegos marcados como "lo tengo", excluyendo expansiones sueltas — no
  son jugables por sí solas) por número de jugadores, minutos disponibles y
  modo (cooperativo/competitivo/campaña). Cálculo 100% en el cliente sobre la
  colección ya cargada, sin endpoint ni petición nueva: con el tamaño de
  colección típico de uso personal no hace falta paginar ni filtrar en el
  servidor, y así los filtros responden al instante mientras se ajustan.
- Sistema de diseño visual: paleta propia (teal + ámbar, con variante clara
  vía `prefers-color-scheme`) en `assets/base.css`/`main.css` sustituyendo la
  paleta de ejemplo del scaffolding de `create-vue`, clases reutilizables
  (`.btn`, `.card`, `.badge`, `.form`, `.alert`) aplicadas a todas las
  vistas para no repetir estilos ad-hoc por pantalla, y una cabecera/nav
  propia en `App.vue`. Verificado en el navegador en desktop y en un
  viewport móvil (simulado con un iframe interno, igual que en
  `CV_Optimizer_AI`, porque el redimensionado real de ventana no funciona en
  este entorno de pruebas): las rejillas de tarjetas y los formularios se
  adaptan a una columna sin desbordamientos.

- Despliegue real en capa gratuita: API en Render (Docker, región Frankfurt)
  con Postgres en Neon (región Londres, conexión directa sin pooler — ver
  "Corregido"), y frontend en Cloudflare Pages (región automática vía su red
  global). `api/Dockerfile` construye con `composer:2` y ejecuta en
  `php:8.3-cli-alpine` con el propio servidor de Laravel (sin nginx/php-fpm,
  un solo proceso por contenedor, igual que en `CV_Optimizer_AI`); el
  entrypoint corre `php artisan migrate --force` antes de servir. Verificado
  de punta a punta contra los servicios reales: registro, login, alta y
  borrado de un juego.
- Editar un juego ya añadido (`EditGameView.vue`, ruta `/games/:id/edit`,
  botón "Editar" en cada tarjeta de la colección): reutiliza el mismo
  formulario que el alta manual (extraído a `components/GameForm.vue`) y
  precarga los datos existentes; guarda con `PUT /api/games/{userGame}`.
- Selector "¿A qué jugamos?": con 1 jugador se ocultan los filtros
  cooperativo/competitivo (no hay con quién cooperar o competir en solitario)
  y se resetean si estaban activos; nuevo filtro "Estructura" (Cualquiera /
  Campaña / Arcade — partida suelta), excluyente, como contrapartida directa
  del modo campaña; botón "Buscar" explícito para enviar el formulario
  (el filtrado ya era reactivo, pero no había ninguna acción visible).
- Aviso de "puede tardar unos segundos" en login, registro y formularios de
  juego (`composables/useSlowRequestHint.ts`) cuando una petición lleva
  varios segundos sin resolver — la API en Render tarda hasta ~50s en
  arrancar tras estar inactiva, y sin ningún indicador esa espera parece un
  fallo. También aviso de "Cargando…" mientras se obtiene la colección por
  primera vez en Colección y en el selector.
- Instalable como PWA ("Añadir a pantalla de inicio"): icono propio (un dado
  en los colores de la marca, generado con GD en vez de depender de un
  editor de imágenes externo — ver `web/public/icons/`), `manifest.webmanifest`
  con los tamaños 192/512 (`any` y `maskable`), meta tags de Apple
  (`apple-mobile-web-app-capable`, `apple-touch-icon`) para el comportamiento
  a pantalla completa en iOS, y un service worker mínimo (`public/sw.js`,
  sin ninguna estrategia de caché — la app no tiene historia offline, solo
  existe porque Chrome en Android exige un *service worker* controlando la
  página para ofrecer el diálogo de instalación).
- Editar los datos personales básicos (nombre y email) y cambiar la
  contraseña desde una nueva pantalla "Mi perfil" (`/profile`, enlazada desde
  el nombre de usuario en la cabecera). `PUT /api/user` reutiliza la misma
  validación de unicidad de email que el registro (ignorando al propio
  usuario); `PUT /api/user/password` exige la contraseña actual (regla
  `current_password:sanctum` — por defecto valida contra el guard `web`, hay
  que indicar `sanctum` explícitamente) antes de aceptar la nueva.
- Imágenes de juegos y avatar de usuario, vía URL (sin subida de archivos:
  Render free tier tiene disco efímero, así que cualquier archivo guardado
  en el propio servidor desaparecería en el siguiente despliegue — ver
  `api/README.md`). Los juegos ya tenían `image_url` desde la importación de
  BGG pero nunca se mostraba en ningún sitio; ahora se ve en la colección y
  en el selector (`GameThumbnail.vue`, con el icono del dado de la PWA como
  imagen de repuesto si no hay ninguna), y el alta/edición manual tiene tanto
  un campo para pegar cualquier URL como un botón "Rellenar desde BGG" que
  consulta `GET /api/bgg-lookup/games/{bggId}` (nuevo `BggClient::fetchGameByBggId`,
  una sola llamada a `/thing` — a diferencia de la importación de colección,
  esta consulta es síncrona, sin el estado `202` de exportación en curso).
  El avatar del usuario (`UserAvatar.vue`, con inicial de repuesto si no hay
  imagen) se recupera automáticamente de su cuenta de BoardGameGeek si
  indica su usuario en "Mi perfil": `ProfileController::update` llama a
  `BggClient::fetchUserAvatar()` solo cuando el usuario de BGG cambia (no en
  cada guardado) y de forma best-effort — un fallo aquí (BGG caído, sin
  token, usuario inexistente) nunca bloquea el resto del guardado del
  perfil, simplemente no hay avatar.
- Interruptor de modo claro/oscuro explícito (`ThemeToggle.vue`, icono de sol
  o luna junto al usuario en la cabecera), con el mismo patrón que
  `CV_Optimizer_AI`: la elección se guarda en `localStorage`
  (`ludodex-theme`) y un script en `index.html` la aplica antes de que
  pinte la página para no dar el flash del tema equivocado al recargar. Sin
  elección explícita, `prefers-color-scheme` sigue decidiendo igual que
  antes — el interruptor no cambia el comportamiento por defecto, solo
  permite anularlo.
- El modo de juego (cooperativo/competitivo) en el alta y edición manual de
  un juego pasa de dos casillas independientes a un radio de tres opciones
  (Cooperativo / Competitivo / Ambos, ninguna marcada por defecto — antes
  "Competitivo" venía premarcado). El dato sigue siendo dos flags
  independientes en la base de datos (un juego por equipos puede ser
  cooperativo y competitivo a la vez, ver el fix de `is_competitive` más
  abajo), el radio solo simplifica la elección en el formulario mapeando
  sus tres opciones a esa pareja de booleanos.
- Botón "Solo" junto al campo de jugadores en "¿A qué jugamos?": pone
  Jugadores a 1 de un toque (mismo efecto que escribirlo a mano, ya que
  ambos alimentan el mismo filtro) en vez de tener que abrir el teclado
  numérico en móvil para el caso más común.
- Filtro de "Género" en "¿A qué jugamos?" (mismo campo `categories` que ya
  existía en el alta/edición de juegos, no una taxonomía nueva): solo
  ofrece como opciones las categorías presentes en juegos marcados como
  "Lo tengo", para no mostrar un género que nunca podría dar resultados en
  este selector.
- `TagInput.vue` (usado para mecánicas y categorías en el alta/edición de
  juegos) pasa de un `<input>` con `<datalist>` a un combobox propio: al
  hacer clic o enfocar el campo se listan todas las sugerencias que aún no
  se han elegido, sin esperar a que el usuario empiece a escribir, y la
  lista se sigue acotando a medida que escribe. Se mantiene la posibilidad
  de escribir un valor nuevo que no esté en la lista y añadirlo con Enter.
- Primeros tests con Vitest + Vue Test Utils en el frontend (hasta ahora
  toda la cobertura automática era del backend, vía Pest): `TagInput.vue`
  (sugerencias al enfocar, filtrado al escribir, alta de texto libre,
  no-duplicados), el radio de modo de `GameForm.vue` (mapeo a/desde los
  dos booleanos independientes) y los filtros de `PickerView.vue` (solo
  juegos "Lo tengo" y nunca expansiones, jugadores/género/modo, atajo
  "Solo").

### Corregido

- El favicon (`favicon.ico`) seguía siendo el genérico de la plantilla
  create-vue: el resto de iconos PWA se generaron y sustituyeron en el
  commit que añadió "Añadir a pantalla de inicio", pero `favicon.ico` nunca
  se tocó desde el scaffolding inicial. De paso, todos los iconos (favicon,
  apple-touch-icon, iconos del manifest) pasan a un diseño con más cuidado
  visual: fondo con degradado en vez de color plano, el dado con sombra
  suave y ligeramente inclinado (menos "icono estático", más "dado
  lanzado"), y un pequeño brillo en cada pip. La variante maskable mantiene
  el dado sin inclinar y con más margen respecto al borde, dentro de la
  "zona segura" recomendada para iconos enmascarables. `favicon.ico` pasa a
  ser un .ico real de dos tamaños (16 y 32 px, PNG embebido en cada
  entrada) en vez de heredar el archivo de la plantilla.
- En "¿A qué jugamos?": el campo "Jugadores" empezaba vacío con un
  placeholder ("Cuántos sois") que no cabía junto al botón "Solo" a ese
  ancho y quedaba cortado; ahora arranca con un valor por defecto de 2 (el
  caso más común al abrir la página), y el botón "Solo" respeta ese valor
  por defecto al desactivarse en vez de dejar el campo vacío. También se
  corrige el botón "Buscar", que quedaba alineado con las etiquetas en vez
  de con los controles del formulario (un separador invisible del alto de
  una etiqueta lo baja a la misma línea que el selector de Género).
- El filtro de jugadores/duración del selector dejaba de funcionar (ocultaba
  todos los juegos) en cuanto se vaciaba el campo tras haber escrito algo:
  `v-model.number` en Vue deja el valor como cadena vacía `""` (no `null`)
  cuando el campo queda vacío, y `"" < 3` se evalúa como `0 < 3` en
  JavaScript — así que la ausencia de filtro se interpretaba como "0
  jugadores", por debajo del mínimo de cualquier juego. Encontrado probando
  el selector en vivo con datos reales. Corregido normalizando el valor del
  filtro antes de aplicarlo (cualquier cosa que no sea un número finito
  cuenta como "sin filtro").
- La migración de `games` fallaba en Postgres (no en SQLite, usado en tests
  locales) con `SQLSTATE[42830]: no unique constraint matching given keys`
  al crear la clave foránea autorreferenciada `base_game_id → games.id`:
  Postgres procesa el comando implícito de clave primaria de un blueprint
  después de sus comandos explícitos de clave foránea, así que la FK se
  intentaba crear antes de que `id` tuviera su primary key. Encontrado en el
  primer despliegue real contra Neon. Corregido separando la FK en su propia
  llamada `Schema::table(...)` justo después de `Schema::create(...)`.
- La API devolvía `500` en vez de `401` en peticiones sin token cuando el
  cliente no mandaba `Accept: application/json` (p. ej. abrir un endpoint
  directamente en el navegador): el manejador por defecto de
  `AuthenticationException` intenta redirigir a una ruta `login`, que no
  existe en esta API pura. No es un bug real (el frontend sí manda ese
  header), pero se detectó verificando el despliegue con curl.
- Las migraciones fallaban de forma intermitente contra la cadena de conexión
  *pooled* de Neon (`-pooler` en el host, PgBouncer en modo transacción):
  transacciones DDL con varias sentencias dentro de la misma migración
  quedaban en estado `SQLSTATE[25P02]` (transacción abortada) sin mostrar el
  error real de la primera sentencia fallida. Solucionado usando la conexión
  directa de Neon (sin `-pooler`) como `DB_HOST` — con el tráfico de un
  proyecto personal no hace falta el pooler, y evita esta clase de fallos
  opacos en DDL transaccional.
- La importación desde BGG marcaba `is_competitive` como la negación exacta
  de `is_cooperative` (`BggImportService::upsertGames`), contradiciendo el
  propio comentario de la migración de `games` ("no es un either/or
  estricto... puede ser ninguno o ambos"): un juego semi-cooperativo o por
  equipos (cooperativo dentro del equipo, competitivo entre equipos) quedaba
  marcado como no competitivo solo por ser cooperativo, y cualquier fallo al
  obtener el detalle de BGG (sin mecánicas/categorías) forzaba
  `is_competitive = true` sin ninguna señal real. Encontrado revisando la
  lógica de importación, no en producción. Corregido calculando
  `is_competitive` de forma independiente (a partir de la ausencia de la
  mecánica "Solo / Solitaire Game", no de la negación de cooperativo).
- Los mensajes de validación de la API salían en inglés (`email must be a
  valid email address`) a pesar de que toda la interfaz está en español:
  Laravel usa `APP_LOCALE=en` por defecto y el proyecto nunca había añadido
  traducciones. Corregido con `lang/es/{validation,auth,passwords}.php` y
  `APP_LOCALE=es` (con `APP_FALLBACK_LOCALE=en` como red de seguridad para
  cualquier clave sin traducir).
- El interruptor de tema mostraba el icono equivocado en la primera carga
  sin elección guardada: `useTheme.ts` asumía `'dark'` cuando no encontraba
  el atributo `data-theme`, en vez de comprobar qué tema decidía realmente
  `prefers-color-scheme` en ese navegador. Con un sistema en modo claro, la
  página se veía clara pero el icono ofrecía "cambiar a modo claro" (icono
  de luna) en vez de "cambiar a modo oscuro" — el primer clic no cambiaba
  nada visualmente, solo corregía el icono. Encontrado probándolo en el
  navegador antes de publicarlo. Corregido consultando
  `matchMedia('(prefers-color-scheme: light)')` como valor inicial cuando
  no hay tema guardado.

### Documentado

- **Bloqueante externo, no de esta app**: BoardGameGeek dejó de ofrecer su
  API XML sin autenticación — ahora exige registrar la aplicación en
  <https://boardgamegeek.com/using_the_xml_api> y usar un token de
  aplicación como `Authorization: Bearer` en cada petición. Descubierto al
  intentar verificar el hito 3 contra una colección real (la API respondía
  `401` con `WWW-Authenticate: Bearer realm="xml api"`). Solicitud de
  aplicación ("LudoDex") ya enviada a BGG el 2026-08-08, estado `pending`;
  BGG avisa que la revisión puede tardar una semana o más. El código ya
  soporta el token vía `BGG_APPLICATION_TOKEN` (falla con un mensaje claro,
  no un 401 en crudo, si no está configurado) — la verificación con una
  colección real queda pendiente de que BGG apruebe la solicitud.
