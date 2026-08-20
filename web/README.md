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
- `src/views/ImportBggView.vue` — importación desde BGG con tres pestañas:
  por usuario (*polling* cada 3s contra el estado de la importación), desde
  el CSV que exporta la propia colección de BGG, o el historial de partidas
  (las dos últimas son síncronas, sin *polling*).
- `src/views/PlaysView.vue` — página "Partidas": lista el historial de
  partidas ya importado (paginado, con "cargar más"); el propio formulario
  de importación vive en `ImportBggView.vue`, no aquí.
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

## Breakpoints del formulario de filtros de "¿A qué jugamos?" (`PickerView.vue`)

`.filters` es un flex-wrap con `gap` partido en `column-gap` (espacio entre
elementos de una misma línea, sin tocar) y `row-gap: 0` incondicional — las
filas quedan pegadas entre sí por defecto; donde hace falta separación se
añade a mano (`margin-top` en el elemento que abre la fila siguiente). El
botón de cambio de vista vive siempre en la barra del título, nunca dentro
del formulario, en cualquier ancho. Cada ajuste es acumulativo (`max-width`
o rango), de más ancho a más estrecho:

| Rango | Qué hace |
|---|---|
| `≥1003px` | Todo cabe en la fila 1 salvo Minutos disponibles + Modo, que quedan en fila 2 y crecen (`flex: 1 1 auto` + `justify-content: space-between`) para llenar el 100% del ancho — antes ese hueco lo ocupaba el botón de vista, embebido en el formulario en este rango. |
| `807-1002px` | Buscar/Jugadores/Estructura en fila 1; Género + Minutos en fila 2 (Género crece para llenarla); Modo + Ordenar por en fila 3. |
| `481-806px` | Género comparte fila 1 con Buscar/Jugadores/Estructura si cabe, si no baja a su propia fila; Ordenar por se reordena tras Minutos/Modo. |
| `481-646px` | Género se mueve a la fila de Modo en vez de tener fila propia. |
| `481-560px` | Género, Minutos, Modo y Ordenar por, cada uno en su propia fila; Estructura se une a Género en la segunda. `margin-top` en Ordenar por, que aquí queda solo en su fila. |
| `≤480px` | Buscar+Jugadores en fila 1; Estructura+Género en fila 2; Minutos (3+2 centrado) en fila 3; Modo (centrado) en fila 4; Ordenar por en fila 5 (con `margin-top`). |
| `≤388px` | `font-size: 12px` en todos los controles del formulario (labels, legends, selects, botones, radios) — a partir de aquí ya no caben con el tamaño normal (14px). |
| `≤352px` | Recorte extra de `gap`/`min-width` en las etiquetas de Minutos disponibles, encima del `font-size: 12px` del tramo anterior, para que el 3+2 se sostenga hasta un móvil real de ~345px. |

Dos técnicas que se repiten en varios de estos tramos:
- **Forzar un salto de línea real**: un `order` por sí solo no reordena a una
  línea distinta — `flex-wrap` decide la línea de cada elemento por su
  tamaño *hipotético* (el de antes de crecer/encoger), no por su `order`. El
  truco usado aquí es un `.filters::before` vacío con `flex-basis: 100%` y un
  `order` intermedio, que actúa como salto de línea forzado.
- **Recortar `gap`/`padding`/`min-width` antes que perder texto**: en los
  tramos más estrechos (Minutos disponibles, Modo), antes de reducir el
  `font-size` se recortan primero el espacio entre radios y el padding del
  propio `fieldset` — son los mismos tokens de espaciado que en cualquier
  otro sitio de la app (`--space-1`... `--space-4`), nunca valores sueltos
  inventados para la ocasión, salvo cuando ya no queda ningún token por
  debajo de la necesidad real (ahí se pasa a `font-size: 12px`).
