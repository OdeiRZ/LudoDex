# LudoDex

Inventario virtual de juegos de mesa: da de alta tu colección (a mano o
importándola desde [BoardGameGeek](https://boardgamegeek.com)) y usa un
selector con filtros (jugadores, duración, cooperativo/competitivo, modo
campaña...) para decidir a qué jugar. No es una plataforma para jugar online:
es una herramienta de catalogación y elección, pensada primero para uso
personal y abierta a que cualquiera gestione la suya.

**Proyecto en construcción activa** — este README se irá ampliando a medida
que avancen los hitos. Ver [CHANGELOG.md](CHANGELOG.md) para el detalle de
cada uno.

## Estructura

Repo único con dos aplicaciones independientes, cada una con su propio
`README.md`:

- [`api/`](api/README.md) — API REST en Laravel 12 + Sanctum (autenticación
  por token, no por cookie de sesión — ver la nota de arquitectura más abajo).
- [`web/`](web/README.md) — SPA en Vue 3 (Composition API, Pinia, Vue Router,
  TypeScript), consume la API por HTTP.

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
  2026-08-08, pendiente de aprobación (BGG avisa que puede tardar una
  semana o más). Hasta que llegue el token, la importación está construida y
  probada con `Http::fake()` pero sin verificar contra una colección real.

## Hitos

1. ✅ Cimientos: repo, API con auth (registro/login/logout) y SPA con las
   mismas pantallas, verificado de punta a punta en local.
2. ✅ Inventario manual de juegos, con catálogo de mecánicas/categorías
   compartido (reutilizado por nombre, no duplicado) y expansiones ligadas a
   su juego base.
3. 🔶 Importación desde BGG: construida y probada con respuestas simuladas;
   verificación con una colección real pendiente del token de aplicación de
   BGG (ver "Requisitos externos" más arriba).
4. Selector de "a qué jugar" con filtros.
5. Pulido visual y despliegue (Render + Neon + Cloudflare Pages).

Fuera de alcance por ahora, a propósito: amistades/interacción social,
selector combinado con colecciones de amigos, historial de partidas. Puede
revisarse más adelante si tiene sentido.

## Licencia

[MIT](LICENSE).

## Autor

Odei Riveiro Zafra
