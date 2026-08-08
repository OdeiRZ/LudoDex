# Changelog

Todos los cambios notables de este proyecto se documentan en este archivo.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/), y este
proyecto usa [Versionado Semántico](https://semver.org/lang/es/).

## [Unreleased]

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

### Corregido

- El filtro de jugadores/duración del selector dejaba de funcionar (ocultaba
  todos los juegos) en cuanto se vaciaba el campo tras haber escrito algo:
  `v-model.number` en Vue deja el valor como cadena vacía `""` (no `null`)
  cuando el campo queda vacío, y `"" < 3` se evalúa como `0 < 3` en
  JavaScript — así que la ausencia de filtro se interpretaba como "0
  jugadores", por debajo del mínimo de cualquier juego. Encontrado probando
  el selector en vivo con datos reales. Corregido normalizando el valor del
  filtro antes de aplicarlo (cualquier cosa que no sea un número finito
  cuenta como "sin filtro").

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
