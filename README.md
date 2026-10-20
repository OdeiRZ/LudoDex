# LudoDex

[![CI](https://github.com/OdeiRZ/LudoDex/actions/workflows/ci.yml/badge.svg)](https://github.com/OdeiRZ/LudoDex/actions/workflows/ci.yml)

Inventario virtual de juegos de mesa: da de alta tu colección (a mano o
importándola desde [BoardGameGeek](https://boardgamegeek.com)) y usa un
selector con filtros (jugadores, duración, cooperativo/competitivo, modo
campaña...) para decidir a qué jugar. No es una plataforma para jugar online:
es una herramienta de catalogación y elección, pensada primero para uso
personal y abierta a que cualquiera gestione la suya.

**En vivo**: [ludodex.pages.dev](https://ludodex.pages.dev) (frontend,
Cloudflare Pages) — [ludodex-api.onrender.com](https://ludodex-api.onrender.com)
(API, Render). Ambos en capa gratuita: la API "duerme" tras un rato de
inactividad y el primer request tras el sueño puede tardar ~50s en responder
mientras arranca de nuevo.

## De un vistazo

| | |
|---|---|
| **Qué resuelve** | Saber qué juegos tienes y elegir a cuál jugar según cuántos sois, cuánto tiempo hay y qué os apetece; con la colección importable desde BoardGameGeek. |
| **Social** | Amigos, comparar colecciones y ver las partidas de los demás. |
| **Stack** | Laravel 12 + Sanctum (API) · Vue 3 + Pinia + TypeScript · PostgreSQL en Neon · Render + Cloudflare Pages · español e inglés |
| **Calidad** | 313 tests de backend (SQLite y PostgreSQL) · 500 de frontend · CI con comprobación de atribución · Sentry · copia de seguridad semanal cifrada |

**Una mejora medida de verdad:** cargar la app hacía **8 peticiones y tardaba
+3,1 s** en terminar de pintar; con un único `/api/bootstrap` con ETag pasó a
**1 petición, un 304 en ~390 ms** con copia guardada. La lista de juegos bajó de
**660 KB a 266 KB** al sacar las descripciones largas (se piden al abrir un
juego), y las peticiones autenticadas de ~450 a ~285 ms al mover la base de
Londres a Fráncfort. Las mediciones están en [`api/README.md`](api/README.md)
y en el [CHANGELOG](CHANGELOG.md).

**Proyecto en construcción activa** — este README se irá ampliando a medida
que avancen los hitos. Ver [CHANGELOG.md](CHANGELOG.md) para el detalle de
cada uno, o las [releases en GitHub](https://github.com/OdeiRZ/LudoDex/releases)
para el resumen de cada versión publicada.

## Estructura

Repo único con dos aplicaciones independientes, cada una con su propio
`README.md`:

- [`api/`](api/README.md) — API REST en Laravel 12 + Sanctum (autenticación
  por token, no por cookie de sesión — ver la nota de arquitectura más abajo).
- [`web/`](web/README.md) — SPA en Vue 3 (Composition API, Pinia, Vue Router,
  vue-i18n, TypeScript), consume la API por HTTP.

## Por qué esta arquitectura

- **API y frontend separados, en vez de un monolito con Inertia**: es
  deliberado. Ya existe un proyecto de portfolio (`CV_Optimizer_AI`) que
  muestra ese patrón; aquí se explora el otro extremo — una API propiamente
  dicha consumida por una SPA independiente.
- **Autenticación por token Bearer (Sanctum Personal Access Tokens), no por
  cookies de sesión**: al ir 100% en capas gratuitas, la API y la SPA viven en
  dominios distintos (`*.onrender.com` vs `*.pages.dev`, sin dominio propio).
  Las cookies de sesión entre dominios distintos chocan cada vez más con las
  restricciones de cookies de terceros de los navegadores modernos; un token
  Bearer evita ese problema por completo, a cambio de guardarse en
  `localStorage` (legible por cualquier script de la página) en vez de en una
  cookie `httpOnly`. Aceptado conscientemente para un proyecto de portfolio
  sin datos sensibles.
- **Importación desde BGG sin *worker* en segundo plano**: la API de
  colecciones de BGG responde `202` mientras genera el export y hay que
  reintentar. En vez de una cola persistente (que el free tier de Render no
  soporta), el propio frontend hace *polling* contra un endpoint que reintenta
  cada vez — sin infraestructura adicional.
- **Postgres en [Neon](https://neon.tech), no en Supabase**: ambos tienen capa
  gratuita, pero Supabase pausa el proyecto tras una semana de inactividad y
  hay que reactivarlo a mano desde su panel. Neon se reactiva solo en la
  primera consulta — mejor encaje para una pieza de portfolio con tráfico
  esporádico.
- **Sin *ping* periódico para evitar el sueño de la API**: se consideró un
  *keep-alive* (un GitHub Action con `schedule:`, o un servicio externo tipo
  UptimeRobot) que golpease la API cada pocos minutos para que Render nunca
  la viera inactiva. Se descartó a propósito: el free tier da 750 horas de
  instancia al mes por workspace, y un mes tiene ~744 — mantenerla siempre
  despierta consumiría prácticamente el cupo entero, dejando sin margen a
  cualquier otro servicio gratuito del mismo workspace. Para un proyecto de
  portfolio con tráfico bajo e impredecible, se prefiere asumir el retraso
  ocasional (documentado arriba) antes que arriesgarse a quedarse sin cupo.

## Requisitos externos

- **Token de aplicación de BoardGameGeek**: BGG dejó de ofrecer su API XML
  (`xmlapi2`) sin autenticación — ahora exige registrar la aplicación y enviar
  un token como Bearer en cada petición (ver
  [Using the XML API](https://boardgamegeek.com/using_the_xml_api) y el hilo
  [Registration and Authorization coming to the XML API](https://boardgamegeek.com/thread/3492262/registration-and-authorization-coming-to-the-xml-a)).
  Descubierto al verificar el hito 3 contra una colección real: la API
  respondía `401` con cabecera `WWW-Authenticate: Bearer realm="xml api"` — un
  cambio de política de BGG posterior a como funcionaba históricamente esta
  API, no un fallo de esta app. Sin `BGG_APPLICATION_TOKEN` configurado (ver
  [`api/README.md`](api/README.md)), la importación falla con un mensaje
  explicando el motivo en vez de un error críptico.
  **Estado**: solicitud de aplicación ("LudoDex") enviada a BGG el
  2026-08-08 y **aprobada el 2026-08-12**. Con el token ya configurado, la
  importación por usuario quedó verificada de punta a punta contra una
  colección real de 304 juegos (BGG puede listar el mismo juego más de
  una vez en la exportación en crudo — la 0.5.0 depura esos duplicados
  antes de escribir nada, ver CHANGELOG). La pestaña "Desde CSV" de
  Importar BGG sigue disponible como alternativa sin token (ese fichero
  viene de la sesión del propio usuario, no de la API) — verificada de
  punta a punta contra una colección real de 281 juegos.
- **La relación con BGG es de solo lectura**: tanto la importación por
  usuario como por CSV y "Rellenar desde BGG" únicamente leen datos de
  BGG hacia LudoDex — la app nunca escribe ni sincroniza nada de vuelta,
  ni la colección ni ninguna edición manual. Ojo, esto no significa que
  una corrección manual sea intocable: una reimportación vuelve a traer
  y sobrescribir la mayoría de campos de un juego con lo que diga BGG en
  ese momento (nombre, año, ranking, valoración, jugadores…); solo las
  mecánicas/categorías (aditivas, nunca se quitan una ya puesta a mano) y
  el juego base de una expansión (si ya tiene uno asignado, no se
  recalcula) quedan protegidas frente a reimportaciones futuras.
- **Traducción de descripciones (DeepL, opcional)**: la descripción de
  cada juego (importada de BGG, solo en inglés) se puede traducir al
  español bajo demanda desde el modal de detalles ("ver descripción"),
  tanto en Colección como en "¿A qué jugamos?", vía la API gratuita de
  [DeepL](https://www.deepl.com/en/signup?product=api_free)
  (`DEEPL_API_KEY`, ver [`api/README.md`](api/README.md)). Se guarda una
  sola vez por juego (`games` es un catálogo compartido entre toda la
  colección, no por usuario), y sobrevive a vaciar y reimportar la
  biblioteca — la importación nunca toca ese campo. Sin clave configurada
  (o si DeepL falla), la app sigue funcionando con normalidad: se muestra
  el texto original en inglés con una etiqueta "EN", sin ningún error
  visible. Probado de punta a punta con una clave real, tanto en local
  como en producción (Render).
- **Envío real de email (recuperación de contraseña y verificación de
  cuenta)**: en producción (Render) la API usa un mailer propio
  (`MAIL_MAILER=gmail_api`, vía la API REST de Gmail) en vez de
  [Resend](https://resend.com) — Render bloquea SMTP saliente, y sin
  dominio propio verificado Resend solo entrega al dueño de su propia
  cuenta. Probado de punta a punta contra un destinatario real
  arbitrario, no solo la cuenta propia. Ver
  [`api/README.md`](api/README.md), "Alternativa a Resend sin dominio
  propio (API de Gmail)", para la configuración manual (el token OAuth
  caduca cada 7 días en modo Prueba y hay que renovarlo a mano).

## Hitos

1. ✅ Cimientos: repo, API con auth (registro/login/logout) y SPA con las
   mismas pantallas, verificado de punta a punta en local.
2. ✅ Inventario manual de juegos, con catálogo de mecánicas/categorías
   compartido (reutilizado por nombre, no duplicado) y expansiones ligadas a
   su juego base.
3. ✅ Importación desde BGG: por usuario, verificada de punta a punta contra
   una colección real (304 juegos) con el token de aplicación ya aprobado;
   desde CSV, alternativa sin token también verificada contra una colección
   real (ver "Requisitos externos" más arriba).
4. ✅ Selector de "a qué jugar": filtros de jugadores, duración disponible y
   modo (cooperativo/competitivo/campaña) sobre los juegos marcados como "lo
   tengo", excluyendo expansiones sueltas (no son jugables por sí solas).
5. ✅ Pulido visual (paleta propia, componentes reutilizables, micro-
   interacciones en botones/cards/badges, skeleton loaders y un gráfico de
   actividad mensual en "Tus partidas", verificado en desktop y móvil) y
   despliegue real: API en [Render](https://render.com) (Docker, Frankfurt)
   + Postgres en [Neon](https://neon.tech) (Fráncfort desde el 2026-10-14;
   antes Londres, a un océano de distancia del API) + frontend en
   [Cloudflare Pages](https://pages.cloudflare.com). Verificado de punta a
   punta contra los servicios reales (registro, login, alta y borrado de un
   juego) tras el despliegue.
6. ✅ Amistades: solicitudes de amistad, comparación de colección y
   partidas de un amigo, el selector "¿A qué jugamos?" extendido para
   jugar con la colección combinada de varios amigos a la vez (no solo
   uno), y bloqueo de usuario + visibilidad de colección/partidas
   configurable por perfil (en 4 fases, ver CHANGELOG). Cierra el área
   que este README dejaba fuera de alcance a propósito.

## Copias de seguridad

Neon gratuito solo conserva 6 horas de historial, así que
`.github/workflows/backup.yml` hace cada domingo (y a mano) un volcado de la base
de datos, lo **cifra** (el repositorio es público y sus artefactos los puede
descargar cualquiera con sesión de GitHub) y lo guarda 90 días como artefacto.
No lleva sesiones, tokens de acceso ni cachés. Después **comprueba que la copia
se puede restaurar** (la restaura en un PostgreSQL desechable del propio runner y
cuenta filas): una copia que nunca se ha restaurado no es una copia. Sin coste:
Actions es gratuito en repositorios públicos y el volcado son pocos MB.

**Puesta en marcha** (una vez; los valores los pones tú):

```
gh secret set NEON_DATABASE_URL   # cadena de conexión DIRECTA de Neon (no la "pooled")
gh secret set BACKUP_PASSPHRASE   # contraseña de cifrado; guárdala aparte
gh workflow run backup.yml        # primera copia, para comprobar que funciona
```

**Restaurar o mudar de base de datos** (p. ej. de Londres a Fráncfort), sin
instalar nada ni pasar la contraseña por ningún ordenador personal:
`.github/workflows/restore-database.yml` descifra una copia y la restaura en OTRA
base de datos dentro de GitHub.

1. Crear la base de datos de destino, vacía, en el proyecto nuevo de Neon.
2. Guardar su cadena **directa** como secreto `RESTORE_TARGET_DATABASE_URL`.
3. Lanzar `backup.yml` (copia fresca) y anotar el número de su ejecución.
4. `gh workflow run restore-database.yml -f backup_run_id=<id>` y comparar los
   recuentos que imprime (juegos, colecciones, partidas, usuarios, amistades,
   mecánicas) con los del origen.
5. Cambiar las variables `DB_*` de Render a la base nueva y **lanzar un
   despliegue manual** (guardar las variables no basta), y actualizar el secreto
   `NEON_DATABASE_URL` para que las copias sigan la base nueva.

Se niega a restaurar sobre una base con tablas o sobre el propio origen de las
copias. Al restaurar hay que **volver a iniciar sesión** (los tokens no se
copian a propósito).

## Licencia

[AGPLv3](LICENSE).

## Autor

Odei Riveiro Zafra
