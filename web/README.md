# LudoDex Web

SPA en Vue 3 (Composition API, Pinia, Vue Router, TypeScript) para
[LudoDex](../README.md). Consume la [API](../api/README.md) por HTTP con un
token Bearer guardado en `localStorage`.

## Instalación

```bash
npm install
cp .env.example .env.local   # ajusta VITE_API_URL si la API no corre en localhost:8000
npm run dev
```

## Scripts

```bash
npm run dev          # servidor de desarrollo
npm run build         # type-check (vue-tsc) + build de producción
npm run lint          # ESLint (con --fix)
npm run format         # Prettier
npm run test:unit      # Vitest
```

## Estructura relevante

- `src/lib/api.ts` — instancia de axios con interceptor que añade el token
  Bearer a cada petición.
- `src/stores/auth.ts` — store de Pinia: sesión (usuario + token), acciones de
  registro/login/logout, y restauración de sesión al recargar la página.
- `src/router/index.ts` — rutas y guard de navegación (`requiresAuth` /
  `guestOnly`).
- `src/stores/games.ts` — store de Pinia: colección del usuario, catálogo de
  mecánicas/categorías, altas y borrados.
- `src/components/TagInput.vue` — selector de mecánicas/categorías como
  "tags", con autocompletado (`<datalist>`) contra el catálogo existente y
  alta de nuevas sobre la marcha.
- `src/views/DashboardView.vue` / `AddGameView.vue` — listado de la colección
  y formulario de alta manual.
- `src/views/ImportBggView.vue` — importación desde BGG con dos pestañas: por
  usuario (*polling* cada 3s contra el estado de la importación) o desde el
  CSV que exporta la propia colección de BGG (síncrona, sin *polling*).
- `src/views/PickerView.vue` — selector "¿A qué jugamos?": filtra la
  colección propia en el cliente por jugadores, duración y modo de juego.

## Breakpoints del header (`App.vue`)

La cabecera (marca, nav de 4 enlaces, avatar, sesión) tiene varios ajustes
progresivos por ancho, cada uno acumulativo con los anteriores (`max-width`,
no rangos exclusivos por tipo de dispositivo):

| `max-width` | Qué hace |
|---|---|
| `872px` | Oculta el nombre junto al avatar y evita que "Cerrar sesión" se parta en dos líneas |
| `786px` | "Cerrar sesión" pasa de texto a solo icono |
| `702px` | Oculta el texto "LudoDex" (queda solo el dado 🎲) y el avatar salta al extremo izquierdo del header, junto al dado |
| `618px` | El dado desaparece por completo; el avatar se queda solo, anclado a la izquierda |
| `576px` | El nav de 4 enlaces se sustituye por un botón ☰ que despliega un panel vertical |

Los umbrales exactos (872/786/702/618/576) salieron de medir en el propio DOM
los anchos reales de marca/nav/sesión al añadir un 4º enlace ("Partidas"),
no de valores estándar de diseño — si se añade o quita algún enlace del nav,
hay que volver a medir en vez de asumir que siguen siendo válidos.

## Breakpoints del toolbar de Colección (`DashboardView.vue`)

`.dashboard-toolbar` es una única rejilla CSS Grid (título, buscador+tipo,
orden+extras); varias filas comparten columnas para poder "acoplar"
controles entre filas (p. ej. eliminar/añadir alineados con la fila de
orden). Igual que en `App.vue`, cada ajuste es acumulativo (`max-width`):

| `max-width` | Qué hace |
|---|---|
| `880px` | Vaciar/Añadir pasan a solo icono, agrupados con el toggle de vista a la derecha. Buscador+tipo no se parten en dos líneas. |
| `740px` | Buscador+tipo y orden+extras ya no caben en una línea → dos filas de grid. Vaciar/Añadir recuperan su etiqueta de texto. |
| `671px` | Vaciar/Añadir vuelven a solo icono. |
| `583px` | Buscador+tipo ocupan el 100% de la primera línea. Orden, su toggle, vista, eliminar y añadir se agrupan a la izquierda en la segunda línea. |
| `389px` | Vista sube a la línea del título (a la derecha). Eliminar y añadir se agrupan juntos en la línea de orden (a la derecha, eliminar primero). `.search-group` deja de ser parte de la rejilla compartida y pasa a ser una fila flex propia de ancho completo — el buscador y el select de tipo se reparten el espacio de forma proporcional entre sí (`flex: 1` ambos), sin depender de las columnas de eliminar/añadir/orden. |

Dos lecciones de CSS Grid que costó descubrir y que conviene recordar si se
retoca este bloque:
- Una columna `auto` (o un `minmax(N, 1fr)` con `N` fijo) se dimensiona según
  el contenido más ancho que comparta esa columna **en cualquier fila** —
  un botón con etiqueta de texto (como el toggle "A → Z") compartiendo
  columna con un botón de solo icono lo estira a su mismo ancho.
- `min-width: 0` (o `minmax(0, 1fr)`) es necesario para que una columna
  pueda encoger por debajo del contenido de sus propios controles (mismo
  motivo por el que un `select` con una opción larga puede impedir que su
  columna encoja) — sin él, la rejilla se desborda en vez de comprimirse.
