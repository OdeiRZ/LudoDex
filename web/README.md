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
- `src/router/index.ts` — rutas, guard de navegación (`requiresAuth` /
  `guestOnly`) y `scrollBehavior` (restaura la posición de scroll guardada
  cuando la navegación es un "atrás" real — botón atrás del navegador, o
  `router.back()`/`forward()`/`go()` — y solo en ese caso; una navegación
  normal hacia una página nueva sigue empezando arriba). Guardar o
  eliminar en `EditGameView.vue` usan `router.back()` en vez de
  `router.push()` precisamente para aprovechar esto: sin ello, volver de
  editar un juego a mitad de un scroll largo de la colección siempre
  devolvía a la parte superior de la página.
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
  (las dos últimas son síncronas, sin *polling*). Los nombres de pestaña
  ("Por usuario"/"Desde CSV"/"Partidas") mezclan a propósito método y tipo
  de dato: las dos primeras son dos métodos distintos de importar lo mismo
  (la colección), así que llamar a la primera "Colección" en vez de "Por
  usuario" daría a entender que la de CSV no lo es — Partidas no tiene ese
  problema porque solo existe un método para ella.
- `src/views/PlaysView.vue` — página "Partidas": lista el historial de
  partidas ya importado (paginado, con "cargar más" y numeración
  correlativa que no se reinicia por página); el formulario de
  importación inicial vive en `ImportBggView.vue`, no aquí. El botón junto
  al título despliega un panel para reimportar (mismo import incremental,
  solo pide de nuevo el usuario de BGG) sin salir de la página — junto al
  título y no al buscador, ya que es una acción de página, no de
  búsqueda. El buscador por nombre de juego filtra en el backend (`GET
  /api/plays?search=`), no en el cliente como Colección/¿A qué jugamos? —
  la lista está paginada, así que un filtro local solo vería la página ya
  cargada. Encima de la lista, un bloque de estadísticas (`GET
  /api/plays/stats`) con partidas jugadas, juegos distintos, tiempo total
  y un top 3 de más jugados — agregado en el backend sobre todo el
  historial, no derivado de `plays.entries` (que solo tiene las páginas ya
  cargadas); se refresca también tras reimportar.
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
| `850px` | Vaciar/Añadir pasan a solo icono, agrupados con el toggle de vista a la derecha. Buscador+tipo no se parten en dos líneas. La rejilla colapsa a una sola columna (antes `1fr auto`) — la segunda solo la usaban Vaciar/Añadir en su formato con etiqueta, ya ocultos aquí; dejarla reservada sin contenido dejaba el grupo vista/vaciar/añadir 16px corto del borde real (ver segunda lección más abajo). |
| `740px` | Buscador+tipo y orden+extras ya no caben en una línea → dos filas de grid. Vaciar/Añadir recuperan su etiqueta de texto. |
| `671px` | Vaciar/Añadir vuelven a solo icono. |
| `583px` | Buscador+tipo ocupan el 100% de la primera línea. Orden, su toggle, vista, eliminar y añadir se agrupan a la izquierda en la segunda línea. |
| `389px` | Vista sube a la línea del título (a la derecha). Eliminar y añadir se agrupan juntos en la línea de orden (a la derecha, eliminar primero). `.search-group` deja de ser parte de la rejilla compartida y pasa a ser una fila flex propia de ancho completo — el buscador y el select de tipo se reparten el espacio de forma proporcional entre sí (`flex: 1` ambos), sin depender de las columnas de eliminar/añadir/orden. El título parte en dos líneas a este ancho (título y contador), haciendo más alta la fila de la rejilla que comparte con el toggle de vista — `align-self: end` (en vez del `align-items: center` general del toolbar) alinea el toggle a la altura del contador en vez de centrarlo entre ambas líneas. |

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
- `column-gap` se reserva entre dos columnas aunque una de ellas esté
  vacía (sin ningún elemento dentro) — un `margin-left: auto` pensado
  para llegar al borde real del contenedor se queda corto exactamente
  ese hueco si la rejilla sigue teniendo una segunda columna sin usar,
  en vez de haberla colapsado a una sola.

### Tira de salto rápido — letras o décadas (prototipo)

Solo se muestra mientras `sortCriterion` es `'name'` o `'year'` y hay más
de 12 juegos en `filtered` — "saltar a la L" (o "a 1994") no tiene un
significado coherente ordenado por ranking BGG, que es un rango casi sin
límite superior y con la mayoría de juegos sin ranking: no existe ahí un
conjunto pequeño y natural de "cajones" como el alfabeto o las décadas,
así que no se ha construido un tercer panel para ese caso — necesitaría
un diseño distinto (tramos de ranking, no una casilla por número). Un
único elemento fijo (`.az-scrubber`, `position: fixed`) hace de zona de
detección y de tira visual a la vez, para ambos modos.

Cada `<li>` de la lista lleva sus dos atributos siempre calculados,
independientemente de cuál esté en uso: `data-letter` (primera letra del
nombre, mayúscula, sin acentos vía `normalize('NFD')` + regex de marcas
combinantes) y `data-year-bucket` (década del `year_published`,
`Math.floor(year / 10) * 10`, o `'?'` si no tiene año conocido). El punto
vertical donde cae el puntero dentro de la tira (`clientY` relativo a su
propio `getBoundingClientRect()`) se traduce a un índice sobre
`scrubberBuckets` (la lista de "cajones" del modo activo: el alfabeto fijo
para nombre, o las décadas que existan de verdad en la colección para
año) — no se calcula cajón por `<span>` individual, lo que permite
arrastrar de un tirón sin perder eventos entre ellos (`setPointerCapture`
en el `pointerdown` mantiene los `pointermove` dirigidos al mismo
elemento aunque el dedo/cursor salga de sus límites).

Las décadas de año se calculan sobre `games.collection` completa, no
sobre `filtered` — igual que el alfabeto es una constante fija en vez de
derivarse de lo que hay visible ahora mismo, así los "cajones" no
aparecen ni desaparecen mientras el usuario escribe una búsqueda, solo
cambia cuáles están disponibles (atenuadas si no).

El índice se resuelve sobre `displayBuckets` (un `computed`), no sobre
`scrubberBuckets` directamente — con `sortOrder === 'desc'` el alfabeto
se invierte entero (`[...ALPHABET].reverse()`), para que el orden visual
de la tira siga siempre al mismo orden en el que está la lista debajo:
sin esto, con la lista en "Z → A", tocar arriba de la tira (donde antes
seguía apareciendo "A") saltaba al final de la lista en vez de al
principio. Las décadas se invierten igual, pero el cajón `'?'` (sin año)
se queda siempre al final en los dos sentidos — los juegos sin año
también se quedan siempre al final de la propia lista (ver el
comentario del propio sort por año, más arriba en este componente), así
que a diferencia de "#" en el alfabeto (que sí reordena junto al resto,
al ser un nombre cualquiera ordenado por `localeCompare` normal), aquí no
tendría sentido que reordenase.

Ese cajón `'?'` solo existe de verdad (`hasUnknownYear`) mientras al
menos un juego de la colección carezca de año — si todos lo tienen, ni
`yearBuckets` ni `displayBuckets` lo incluyen, y desaparece de la tira
por completo en vez de quedarse siempre atenuado sin nada que alcanzar
(preguntado directamente al ponerle año al único juego que no lo tenía).

`nearestAvailableBucket` sigue
recorriendo `scrubberBuckets` en su orden canónico (A→Z o década más
antigua primero) para buscar el cajón disponible más cercano — esa
búsqueda es sobre proximidad dentro del propio conjunto de cajones, no
sobre proximidad visual en la tira, así que no le afecta cuál de los dos
órdenes se esté mostrando.

Un cajón sin ningún juego en el filtro/búsqueda actual salta al cajón
disponible más cercano (recorriendo `scrubberBuckets` en ambas
direcciones) en vez de no hacer nada.

Transparencia por proximidad: `opacity: 0.4` en reposo (probado con
`opacity: 0` primero, pero en un móvil real no dejaba ninguna pista visual
de dónde estaba la tira — nada que tocar a ciegas). Un `pointerenter` la
lleva a `opacity: 1`, que es la confirmación de que el toque ha caído
dentro de la tira; un `pointerleave` la devuelve a 0.4. Se probó también
a agrandarla un poco (`scale()`) al revelarse, pero se descartó (pedido
directamente): escalar el contenedor desplaza la posición en pantalla de
cada cajón respecto al propio dedo/puntero que lo está arrastrando,
justo la referencia que el arrastre necesita mantener fija — el tamaño
base de la tira (padding, tamaño de letra) es el que hay que tocar si
hace falta más grande, no un efecto al pasar por encima. El ratón tiene
un hover real antes de
pulsar; el táctil no tiene ninguna señal de "acercarse" antes del
contacto, así que en ese caso el efecto solo llega en el instante del
toque (`pointerdown`) y se deshace explícitamente al soltar (`endScrub`
fuerza `hovering = false` cuando `event.pointerType !== 'mouse'`, ya que
un `pointerleave` no tiene sentido para un dedo que ya no está en
pantalla). `.az-scrubber-bubble` (la burbuja grande que muestra el cajón
actual mientras se arrastra) usa `min-width` en vez de un ancho fijo,
para poder mostrar sin recortarse tanto una sola letra como una década de
4 dígitos ("1990").

Posición horizontal: `right` no es un valor fijo, sino
`max(4px, calc((100vw - 1024px) / 2 + var(--space-4) - var(--space-2)))`.
`#app` (en `main.css`) tiene `max-width: 1024px; margin: 0 auto`, así que
`(100vw - 1024px) / 2` es exactamente el margen vacío que queda fuera de
`#app` en un monitor ancho (0 o negativo por debajo de ese ancho, de ahí
el `max()`); sumar el padding derecho de `#app` (`var(--space-4)`) llega
hasta el borde real del contenido, y restar `var(--space-2)` separa la
tira de ese borde en vez de pegarla encima. Anclarla solo a `right: 4px`
(como al principio) la dejaba pegada al borde de la ventana del
navegador en vez de al de la propia colección — en un monitor ancho
quedaba lejísimos de las tarjetas, flotando sola sobre fondo vacío. Por
debajo de 1024px de ancho, la fórmula converge al mismo `4px` de antes
(no hay margen que restar). `.az-scrubber-bubble` usa la misma fórmula
más `2.5rem` fijos (la separación original entre burbuja y tira cuando
esta última aún vivía en `right: 4px`), para mantenerse pegada a la tira
también aquí. `1024px`/`var(--space-4)` no están enlazados con
`main.css` por ningún mecanismo — si esos valores cambian ahí, hay que
actualizarlos también aquí a mano.

Tamaño fluido (pedido directamente: que la tira encoja de forma continua
con el ancho de pantalla, no en un salto brusco a un breakpoint):
`font-size`, `padding` y `height` usan cada uno un `clamp()` que
interpola entre un valor mínimo a 360px de ancho de viewport y un
máximo a partir de 1024px (el mismo par de anchos que ya usa la
posición horizontal, por consistencia). El primer intento escribió la
interpolación como `(100vw - 360px) / 664px` (una longitud dividida
entre otra, para obtener una fracción de 0 a 1) — este navegador
descarta silenciosamente cualquier `calc()` que divida una longitud
entre otra longitud (comprobado directamente: la regla, leída desde la
hoja de estilos ya parseada, tenía esas propiedades vacías, no solo mal
calculadas). La solución es no dividir nunca — la pendiente de la recta
se resuelve a mano de antemano y se escribe como un número plano pegado
a la propia unidad `vw` (p. ej. `21.0843vw` es un único valor con
unidad, no una operación), sumado a una constante en `px`; sumar dos
unidades absolutas distintas dentro de `calc()` sí es una operación
siempre soportada. Si estos rangos (mínimo/máximo, 360px/1024px) cambian
en el futuro, hay que resolver la recta de nuevo a mano de la misma
forma, no reintroducir una división.

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

Aparte del formulario, `.title-row` (título + contador + botón de vista) es
un flex-wrap propio, sin ningún ajuste hasta un real ~346px: por defecto,
si no caben los tres, el último en orden de flujo (el botón de vista) es
el primero en bajar de línea, y solo si el título + botón tampoco caben
baja también el contador — y cuando ambos acababan compartiendo la
segunda línea, salían en el orden "botón, contador" en vez de "contador,
botón" (un primer intento con `order` invertía la prioridad de quién cede
primero, pero eso mismo determina también el orden visual). `≤346px`
pasa `.title-row` a una rejilla en vez de flex, igual que ya hace el
toolbar de "Tu colección" en su propio tramo estrecho: título en su
propia fila, contador debajo, y el botón en una columna aparte que
abarca ambas filas con `align-self: end` para quedar a la altura del
contador — mismo aspecto en las dos páginas, sin la inconsistencia de
`order`. Sin separación propia entre título y contador al apilarse
(`row-gap: 0`, título y contador quedan pegados a propósito); el aire
respecto al formulario de filtros de debajo vive en el propio
`margin-bottom` de `.title-row` (no en el del `h1`, que deja de ser lo
último del bloque en cuanto algo se apila debajo suyo — reportado
directamente tras notar que faltaba ese margen). El resumen de filtros
colapsado (`.filters-summary`, el texto con los filtros aplicados)
reduce el `padding` heredado de `.card` (1.5rem) a 0.75rem, al ser solo
una línea de texto.

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
