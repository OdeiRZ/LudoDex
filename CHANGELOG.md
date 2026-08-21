# Changelog

Todos los cambios notables de este proyecto se documentan en este archivo.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/), y este
proyecto usa [Versionado Semántico](https://semver.org/lang/es/).

## [Unreleased]

### Añadido

- Página "Partidas": muestra el historial de partidas jugadas, importado
  desde BGG (solo lectura, igual que la colección) — el formulario de
  importación vive como tercera pestaña ("Partidas") dentro de "Importar
  BGG", junto a "Por usuario" y "Desde CSV", en vez de repetirse en la
  propia página de Partidas; su estado vacío enlaza directamente a esa
  pestaña. Por ahora solo guarda juego, fecha, duración y cantidad por
  partida — sin jugadores ni puntuaciones. Si una partida es de un juego
  que aún no está en el catálogo local, se crea automáticamente con sus
  datos de BGG. Pulsar la portada de una partida abre el mismo modal de
  detalle (portada, nombre, descripción, traducción) que el icono del ojo
  en Colección/¿A qué jugamos? — el propio modal se generalizó para
  aceptar un juego "delgado" como el de Partidas (sin mecánicas ni
  categorías) en vez de exigir siempre el objeto `Game` completo, y su
  botón de traducir ya no depende de que el juego esté en la colección
  cargada para reflejar el resultado. Portadas de la lista un poco más
  grandes (40px → 56px). Cada partida lleva delante un número correlativo
  ascendente que no se reinicia al pulsar "Cargar más". Buscador por
  nombre de juego (con *debounce*, filtrado en el propio backend ya que
  la lista está paginada, a diferencia del filtrado en el cliente de
  Colección/¿A qué jugamos?) para encontrar rápido cuándo se jugó a un
  juego concreto sin recorrer todo el historial. Reimportar partidas ya
  no vuelve a traer el historial completo desde BGG cada vez: se pide
  solo desde la última partida ya guardada (con una semana de margen de
  solapamiento por si se edita algo reciente en BGG); una edición más
  antigua que ese margen sí necesitaría un reimport completo para
  reflejarse. El primer import de un usuario sigue trayendo el
  historial entero, al no haber nada guardado aún que filtrar.
- Cuarto enlace de nav ("Partidas") junto a los ya existentes, lo que
  obligó a revisar toda la cabecera para anchos estrechos: por debajo de
  576px el nav se sustituye por un menú ☰ desplegable en vez de partir los
  enlaces en dos líneas. Detalle completo de los breakpoints en
  [`web/README.md`](web/README.md#breakpoints-del-header-appvue).
- Revisión completa de los breakpoints del toolbar de "Tu colección"
  (buscador, filtro de tipo, orden, densidad, vaciar/añadir) para anchos
  estrechos, hasta un móvil real de ~360px sin desbordamiento. Detalle en
  [`web/README.md`](web/README.md#breakpoints-del-toolbar-de-colección-dashboardviewvue).
- Revisión completa de los breakpoints del formulario de filtros de "¿A qué
  jugamos?" (Buscar, Jugadores, Estructura, Género, Minutos disponibles,
  Modo, Ordenar por): filas simplificadas y unificadas de escritorio ancho
  hasta un móvil real de ~345px, botón de cambio de vista siempre en la
  barra del título (antes volvía al formulario por debajo de 481px o por
  encima de 1002px), y `row-gap` a 0 en todo el rango en vez de por tramo.
  Detalle en
  [`web/README.md`](web/README.md#breakpoints-del-formulario-de-filtros-de-a-qué-jugamos-pickerviewvue).

## [0.6.0] - 2026-08-18

### Añadido

- Botón "ver detalles" (icono de ojo) en cada tarjeta, tanto en "Tu
  colección" como en "¿A qué jugamos?", que abre un modal con la
  portada, el título y la descripción del juego (importada de BGG).
  En Colección convive con "Editar"/"Quitar" sin sustituir a ninguno;
  en "¿A qué jugamos?" sustituye al antiguo atajo de edición, que
  encajaba mal en una página pensada para decidir a qué jugar, no
  para gestionar la colección — editar y eliminar quedan así
  centralizados en Colección.
- Traducción al español de la descripción bajo demanda, desde un
  botón "Traducir al español" en ese mismo modal, vía la API gratuita
  de DeepL. Se traduce y se guarda una sola vez por juego (no por
  usuario ni por consulta, ya que el catálogo de juegos es compartido
  entre toda la colección) y sobrevive a vaciar y reimportar la
  biblioteca. Si todavía no hay traducción, si la traducción falla o
  si la app está configurada en inglés, se muestra el texto original
  con una etiqueta "EN" — nunca un error visible.

### Corregido

- La caché de detalles de BGG (24h, por bgg_id) seguía sirviendo, tras
  el despliegue de esta misma versión, respuestas cacheadas de antes
  de que existiera el campo `description` — una reimportación dentro
  de esa ventana de 24h reutilizaba esos datos viejos en vez de
  volver a consultar BGG, así que ningún juego ya cacheado llegaba a
  tener descripción pese a que la funcionalidad estaba desplegada y
  funcionando para cualquier juego nuevo. Clave de caché versionada
  (`bgg:thing:v2:{id}`) para invalidar de golpe lo cacheado con la
  forma antigua.

## [0.5.0] - 2026-08-18

### Añadido

- Botón flotante para volver arriba rápidamente sin hacer scroll,
  en la esquina inferior derecha, visible a partir de cierto
  desplazamiento.
- En "¿A qué jugamos?", el número de jugadores y el modo campaña
  tienen en cuenta ahora las expansiones marcadas como "Lo tengo" del
  juego base (no las que solo están en la lista de deseados), ya que
  BGG publica el rango combinado en la ficha de la propia expansión.
- El panel de filtros de "¿A qué jugamos?" se puede plegar con un
  botón (icono de sliders), dejando solo un resumen de los filtros
  activos — útil para centrarse en los resultados sin desplazarse
  tanto. El botón de cambio de vista (compacta/cómoda) se relocaliza
  junto al título de la sección de forma consistente, en vez de vivir
  embebido en el formulario según el ancho de pantalla.
- Ordenación por nombre o ranking BGG en "¿A qué jugamos?", igual que
  ya tenía "Tu colección" — de paso se reagrupan sus filtros
  (Estructura y Género suben junto a Buscar/Jugadores) para dejar
  hueco al nuevo control.
- Ordenación por año de publicación, en "Tu colección" y "¿A qué
  jugamos?", junto a nombre y ranking. A diferencia del ranking, el
  año no depende de si el juego es una expansión (BGG sí publica año
  para packs y expansiones), así que la opción no se deshabilita según
  el filtro de tipo.
- Icono (un check para "Lo tengo", un corazón para "Lo quiero") junto
  al texto de la insignia de estado en "Tu colección", para
  diferenciarlas más rápido de un vistazo sin depender solo del color.

### Cambiado

- Repaso a fondo del diseño responsive de "Tu colección" y "¿A qué
  jugamos?", contrastado contra móviles reales (hasta 360px) en vez
  de solo simulaciones de ancho — filtros, buscador y tarjetas quedan
  alineados en tablet y en los teléfonos más estrechos en vez de
  desbordar o partirse de forma inconsistente.
- Cabecera: el logo se oculta en pantallas muy estrechas, el nombre de
  usuario se abrevia si hace falta, y el bloque de navegación central
  (Colección / ¿A qué jugamos? / Importar BGG) queda centrado entre el
  logo y los controles de sesión en vez de pegado al logo.
- El enlace "← Volver" en Añadir/Editar juego pasa a estar junto al
  título, a la derecha, en vez de en su propia línea encima — en todas
  las resoluciones.
- Miniatura de portada más grande en el formulario de juego, y mejor
  centrada frente al bloque de nombre + URL de imagen.
- "Importar de BoardGameGeek (opcional)" se acorta a "Importar de BGG
  (opcional)", y la confirmación de "Vaciar biblioteca" pierde la
  aclaración de qué sobrevive al vaciado, dejando solo el aviso que
  importa.
- El texto de confirmación de borrado de un juego ("¿Seguro? Toca de
  nuevo para eliminar") se acorta a solo "¿Seguro?" en pantallas de
  366px o menos, donde era el texto más largo del botón más estrecho
  de la página.
- Se elimina el campo `notes` por juego: estaba completamente montado
  en el backend (columna, modelo, validación) pero nunca llegó a tener
  un campo en el formulario, así que era inalcanzable desde la propia
  app. El objetivo actual sigue siendo importar y organizar la
  colección, no llevar notas — se puede retomar más adelante si hace
  falta de verdad.
- El año de publicación se muestra en la línea de jugadores/duración
  de cada tarjeta, tanto en "Tu colección" como en "¿A qué jugamos?".
- Las expansiones llevan un borde lateral morado en la tarjeta,
  además de la etiqueta de texto "Expansión de..." ya existente — un
  color que no usa ningún otro estado de la app (tener/querer/
  eliminar), para diferenciarlas de un vistazo en colecciones largas.
  Los juegos base se quedan sin marcar a propósito: con solo dos
  estados posibles, marcar la excepción ya basta, y marcar ambos solo
  añadiría ruido visual sin aportar información nueva.
- La etiqueta "Sin ranking en BGG"/"#N en BGG" deja de mostrarse en
  las expansiones: ninguna tiene ranking propio en BGG (el ranking es
  un dato del juego base), así que siempre decía lo mismo sin aportar
  nada. El criterio de ordenación por ranking se deshabilita (sin
  desaparecer del selector) mientras el filtro de tipo está en "Solo
  expansiones", y vuelve a "Nombre" si estaba seleccionado justo
  cuando se cambia a ese filtro.
- Las etiquetas "Expansión de [nombre]" muy largas se truncan con
  puntos suspensivos en vez de cortarse a mitad de palabra contra el
  borde de la tarjeta; el nombre completo queda disponible al pasar
  el ratón por encima.
- Las etiquetas del botón que invierte el criterio de ordenación se
  acortan ("1 → N"/"N → 1" para ranking, ▲/▼ para año) para no romper
  el ancho ya ajustado de los controles de "Tu colección" y "¿A qué
  jugamos?"; el selector de criterio se ensancha en resoluciones de
  escritorio para que "Ranking BGG" se lea completo en vez de
  cortado, sin tocar ninguno de los ajustes ya hechos para móvil/
  tablet.
- Las imágenes de portada de cada tarjeta se cargan de forma diferida
  (`loading="lazy"`) en vez de todas de golpe al abrir la colección.
- La contraseña mínima baja de 8 a 6 caracteres, tanto al crear cuenta
  como al cambiarla.
- El borde lateral de las expansiones usa el mismo morado que su
  etiqueta de texto "Expansión de...", en vez de un tono distinto.
- El selector de tipo (Todos/Solo juegos base/Solo expansiones) se
  ensancha en resoluciones de escritorio, igual que ya se hizo con el
  de ordenar.
- Repaso del ancho de tablet (768-1023px) en "¿A qué jugamos?":
  Minutos disponibles y Modo comparten fila con Ordenar por al final
  en vez de ir cada uno por su lado, y el nombre de usuario se oculta
  en la cabecera hasta 800px de ancho (no solo en móvil) para que los
  enlaces de navegación centrales no partan su texto por falta de
  espacio.
- El README aclara que la relación con BGG es de solo lectura: nada
  de lo que se edita en la app se envía de vuelta a BGG.

### Corregido

- Una expansión con más de un juego base candidato en BGG (p. ej. una
  reimplementación empaquetada junto al original) podía quedar sin
  vincular si el candidato válido no era el último que BGG reportaba,
  mostrándose como juego base en vez de como expansión.
- Iniciar una importación de BGG que fallara por un corte de red — el
  móvil se bloquea justo al pulsar "Importar", por ejemplo — obligaba
  a empezar de cero aunque la petición hubiera llegado al servidor; el
  arranque ahora se reintenta de forma segura (reutilizando la
  importación ya en marcha), no solo el sondeo posterior como antes.
- El nombre y avatar del usuario en la cabecera se quedaban en blanco
  al recargar en cualquier página que no fuera la colección.
- Importar desde BGG podía tardar minutos en vez de segundos para una
  colección ya importada antes. La caché de respuestas de `/thing`
  (añadida en la 0.4.0) consultaba la base de datos una vez por cada
  juego para saber qué estaba ya cacheado, en vez de una sola vez para
  todos — con `CACHE_STORE=database` en producción, una colección de
  ~100 juegos suponía otras tantas idas y vueltas a la base de datos
  solo para esa comprobación. Ahora se consulta y se guarda en bloque
  (`Cache::many()`/`Cache::putMany()`), no una vez por juego.
- El mismo problema reaparecía un paso más abajo: resolver las
  mecánicas/categorías de cada juego durante la importación hacía una
  consulta individual por nombre. Ahora se resuelven y crean en
  bloque.
- Las llamadas a BoardGameGeek no tenían límite de tiempo ni dejaban
  ningún registro cuando fallaban — un fallo de conexión real daba un
  error genérico del servidor en vez del mensaje ya preparado para
  ello, y podía dejar colgada la petición indefinidamente. Ahora
  tienen timeout y quedan registradas.
- La sesión caducada solo se detectaba una vez, al cargar la app —
  si el token expiraba a mitad de sesión, cada acción posterior fallaba
  con un error genérico sin indicar que había que volver a iniciar
  sesión. Ahora cualquier petición con sesión caducada cierra la
  sesión y redirige a inicio de sesión con un aviso.
- Quitar un juego o vaciar la biblioteca fallaba en silencio si la
  petición fallaba, sin ningún aviso visible.
- El temporizador de "confirmar borrado" (tanto en la colección como
  en la ficha de edición) no se limpiaba al salir de la página antes
  de que cumpliera sus 4 segundos.
- Cerrar la pestaña o recargarla con cambios sin guardar en el
  formulario de añadir/editar juego no avisaba de nada, a diferencia
  de la importación de BGG, que sí lo hacía.
- La importación de BGG seguía tardando minutos en vez de segundos
  incluso con la caché de arriba funcionando bien (confirmado con
  datos reales: `/collection` 2,44s, detalles de `/thing` 0,38s). El
  cuello de botella real era la escritura en base de datos — un
  `updateOrCreate` por juego (más las consultas de mecánicas/
  categorías y de la colección del usuario) suponía miles de idas y
  vueltas individuales a Neon para una colección de ~500 juegos, 133
  de los ~137 segundos totales. Ahora se hace en bloque (un upsert
  para los juegos, uno para las mecánicas/categorías de todos los
  juegos a la vez, uno para el estado tengo/quiero de toda la
  colección) — de ~2 minutos a 4-5 segundos en una importación real
  de 493 juegos.
- Una colección real de BGG puede listar el mismo juego dos veces
  (una fila tuyo, otra en deseados, por ejemplo) — el nuevo upsert en
  bloque de arriba no lo toleraba (Postgres: "ON CONFLICT DO UPDATE
  command cannot affect row a second time") y la importación fallaba
  al iniciarse. Ahora se elimina el duplicado antes de escribir nada,
  quedándose con "lo tengo" si las dos filas no coinciden.
- En "¿A qué jugamos?", a 360-366px reales, "Hasta 2h" se quedaba
  solo en una tercera línea en vez de compartir la segunda con "Hasta
  1h"/"Hasta 1h30", y las opciones de Modo (Cualquiera/Cooperativo/
  Competitivo) partían a dos líneas en vez de caber en una — ambos
  casos por márgenes calculados sobre un ancho de tarjeta que nunca
  fue el real. El de Modo necesitó un segundo ajuste tras confirmarse
  en un móvil real que el primer margen (un par de píxeles) seguía
  sin ser suficiente.
- En el formulario de añadir/editar juego, a 360-366px reales, Edad
  recomendada y Estructura se apilaban en líneas separadas en vez de
  compartir fila, y las etiquetas de Ranking en BGG/Valoración/
  Complejidad se partían en dos líneas por un margen demasiado
  ajustado sobre su propio ancho de columna.
- En "¿A qué jugamos?", el bloque de filtros se mostraba de
  inmediato al cargar la página mientras la colección aún se estaba
  descargando, con el filtro de Género apareciendo de golpe (y el
  resto de tarjetas junto a él) justo cuando terminaba la carga. Los
  filtros ahora se ocultan hasta que la colección está lista, igual
  que ya hacía "Tu colección" con su propia barra de herramientas.
- El mismo problema de consulta individual a caché reaparecía, a
  menor escala, en la importación por CSV: buscar el nombre del
  juego base para el aviso de una expansión sin vincular (cuando ese
  juego base no está en el propio CSV) se hacía una vez por
  expansión. Ahora se hace en una sola consulta en bloque para todo
  el archivo.
- Tarjetas con distinto número de etiquetas o líneas de texto dejaban
  una franja en blanco por debajo de las más cortas dentro de la
  misma fila de la rejilla — la caja de la portada no heredaba la
  altura que la rejilla ya estira a la fila más alta. Ahora ocupa el
  100% de esa altura, recortando algo más la imagen en vez de dejar
  hueco vacío.
- Cuando una expansión lista en BGG más de un juego base que el
  usuario tiene en su colección a la vez (por ejemplo dos ediciones
  distintas del mismo juego), se enlazaba con el primero que BGG
  reportara en su respuesta en vez de con el más relevante — caso
  real: los packs de escenario de "Arkham Horror: El Juego de
  Cartas" quedaban enlazados a una edición recién anunciada (sin
  apenas valoraciones en BGG todavía) en vez de a la Edición
  Revisada, donde de verdad sigue publicándose contenido. Ahora se
  prioriza el candidato poseído con mejor ranking en BGG. De paso,
  una expansión que ya tiene un juego base asignado (por una
  importación anterior o por una corrección manual desde el
  formulario de edición) ya no se recalcula en importaciones
  posteriores, para no deshacer sin avisar una corrección ya hecha.
- Un año de publicación (o número de jugadores/duración) que BGG
  reporta como `0` en vez de omitir el dato se guardaba como un cero
  real en vez de "sin dato" — visible en juegos como "Poker Dice",
  que aparecía como el más antiguo de toda la colección al ordenar
  por año. Se trata igual que ya se hacía con el ranking a `0` del
  CSV.
- El fondo del aviso de éxito (verde) apenas se distinguía por ser
  semitransparente; ahora es un verde sólido.

## [0.4.0] - 2026-08-14

### Añadido

- Buscador por nombre en "¿A qué jugamos?", junto al resto de filtros
  (jugadores, duración, modo, género) — con una colección de cientos
  de juegos, incluso tras aplicar esos filtros puede quedar una lista
  larga que recorrer. Funciona igual que el de "Tu colección": filtra
  al momento sobre la lista ya cargada, combinándose con el resto de
  filtros activos en vez de sustituirlos.
- Filtro por tipo de juego (solo juegos base / solo expansiones) en
  "Tu colección", combinable con el buscador y el resto de filtros
  existentes.
- Confirmación de doble toque antes de eliminar un juego ("¿Seguro?
  Toca de nuevo") tanto en la ficha de edición como en cada tarjeta de
  "Tu colección" — sustituye al borrado directo de antes, sin revertir
  a un modal aparte. El aviso se desarma solo pasados unos segundos si
  no se confirma.

### Cambiado

- "Rellenar desde BGG" (en el formulario de añadir/editar juego) ya no
  sobrescribe el nombre o la imagen si el campo ya tiene un valor —
  solo rellena lo que esté vacío, con un aviso junto al botón y una
  nota específica por campo cuando se ha mantenido el valor existente
  en vez de traer el de BGG. Evitaba que reimportar/rellenar cambiara
  sin avisar un nombre en español por el nombre canónico (normalmente
  en inglés) que BGG reporta en `/thing`.
- Rediseño de los campos numéricos del formulario (año de juego,
  ranking, valoración, complejidad) para que quepan en una sola fila
  en vez de apilarse; los campos de jugadores y duración pasan a un
  grupo con mínimo/máximo etiquetados ("Min."/"Max.") en vez de solo
  `aria-label`.
- El botón de eliminar juego de cada tarjeta ("Editar"/"Quitar") ahora
  reparte el ancho disponible a partes iguales en vez de usar un ancho
  fijo, para no desbordar en tarjetas estrechas (vista compacta en
  pantallas pequeñas).
- "Vaciar biblioteca" y "Añadir juego" muestran un icono en pantallas
  estrechas en vez del texto completo, para dejar más espacio al resto
  de controles de la barra de búsqueda.
- El filtro "Con modo campaña" de "¿A qué jugamos?" pasa a tener su
  propio grupo con borde, igual que el resto de filtros (Modo, Minutos
  disponibles), en vez de un `<div>` suelto.

### Corregido

- "Rellenar desde BGG" (en el formulario de añadir/editar juego) podía
  dejar la valoración o la complejidad con más decimales de los que
  admite el propio campo (BGG los reporta con 4-5 decimales, p. ej.
  `2.2809`, pero el input solo acepta 2), y el navegador bloqueaba el
  guardado con su propio aviso de formato antes de llegar siquiera al
  backend. Ahora se redondean a 2 decimales al traerlos de BGG, igual
  que ya hacía el importador de CSV — de paso, un valor de `0` (sin
  votos todavía) se trata como "sin dato" en vez de un cero real.
- La miniatura de la portada en el formulario de añadir/editar juego
  quedaba pegada arriba en vez de centrada frente al bloque de nombre
  + URL de la imagen (dos campos apilados, más alto que la propia
  miniatura), por lo que se veía descolgada hacia la parte superior.
- Igual que la anterior, el bloque de estado del importador por
  usuario (spinner/mensaje mientras BGG prepara la exportación) se
  veía pegado a la izquierda en vez de centrado.
- Una importación por nombre de usuario que quedaba en curso (BGG
  puede tardar varios minutos en preparar la exportación) se perdía
  por completo si la pestaña se recargaba o el sistema la suspendía en
  segundo plano (típico al bloquearse el móvil por inactividad): no
  quedaba ningún indicio de que siguiera funcionando en el servidor,
  solo un formulario en blanco. Ahora el id de la importación en curso
  se guarda localmente y se retoma el sondeo automáticamente al volver
  a cargar la página; además, un fallo de red puntual durante el
  sondeo ya no se trataba como un error definitivo (se reintenta en
  vez de quedarse colgado en silencio).
- En el mismo sentido, el propio backend daba por fallida una
  importación tras un único fallo puntual al conectar con BGG, sin
  posibilidad de recuperarse aunque el siguiente sondeo sí hubiera
  tenido éxito. Ahora un fallo de conexión transitorio (a diferencia de
  un usuario de BGG inexistente, que sigue fallando al momento) cuenta
  como intento fallido sin cambiar el estado, y solo se marca como
  fallida tras varios fallos consecutivos.

## [0.3.0] - 2026-08-13

### Añadido

- Año de publicación, edad recomendada, ranking y valoración de BGG como
  nuevos campos del juego, editables desde el formulario de
  creación/edición y rellenados automáticamente al importar el CSV de
  BGG (columnas `yearpublished`, `bggrecagerange`, `rank` y `average`
  respectivamente). La edad recomendada se guarda tal cual la reporta
  BGG (`"10+"`, `"4-12"`...) en vez de intentar reducirla a un único
  número, ya que el formato no siempre es un rango limpio; se muestra
  seguida de la palabra "años", igual que en la propia BGG. Para la
  valoración se usa el campo `average` de BGG (la media simple de las
  puntuaciones) y no `bayesaverage` ("Geek Rating", el valor ajustado
  con el que BGG calcula su propio ranking): puede salir más alto en
  juegos con pocos votos, pero es el número que BGG muestra de forma
  más visible. Un ranking a `0` en el CSV de BGG significa "todavía sin
  suficientes votos para tener puesto", no "desconocido" — se guarda
  como ranking vacío y las tarjetas de la colección y del selector
  muestran una insignia "Sin ranking en BGG" en vez de un "#0" sin
  sentido. El id de BGG del juego (`objectid` en el CSV), aunque ya se
  usaba internamente para no duplicar juegos al reimportar, nunca se
  guardaba ni se mostraba al crear/editar un juego a mano a pesar de
  tener ahí mismo el botón "Rellenar desde BGG" — ahora el propio campo
  de ese id queda enlazado al juego real.
- Botón "Vaciar biblioteca" en "Tu colección", para reiniciar de golpe
  (p. ej. antes de una reimportación limpia) en vez de quitar juego a
  juego. Solo borra las entradas de colección del usuario (su "Lo
  tengo"/"Lo quiero" y notas); los juegos en sí son un catálogo
  compartido y no se tocan, así que el resto de usuarios y los propios
  datos del juego (mecánicas, ranking de BGG, etc.) no se ven
  afectados. Como no hay soft-delete ni forma de deshacerlo, la
  confirmación no es un simple aviso: hay que escribir el número exacto
  de juegos de la colección para que el botón de confirmar se active.
- Año de publicación, edad recomendada, ranking y valoración también al
  importar por usuario y al usar "Rellenar desde BGG" en el formulario
  manual, no solo al importar desde CSV: la API de BGG ya devuelve estos
  cuatro datos en la misma llamada a `/thing` que ambas rutas hacían de
  antes para mecánicas/categorías/complejidad, así que rellenarlos no
  necesita ninguna petición nueva a BGG, solo parsear más del XML que ya
  llegaba. La edad recomendada no viene tal cual en el XML (a diferencia
  del CSV, que ya trae `"10+"` hecho) — se construye añadiendo un "+" al
  valor numérico de BGG (`minage`), igual que la propia web de BGG lo
  muestra ("Ages: 8+"). Un ranking reportado como el texto literal
  "Not Ranked" (habitual en expansiones) se guarda igual que el `0` del
  CSV: como ranking vacío, no como un valor real.
- Aprobada la solicitud de aplicación a la API de BGG — verificado de
  punta a punta contra una colección real (493 juegos) usando ya el
  token, completando así el hito 3 (antes solo probado con
  `Http::fake()`).
- Selector "Ordenar por" en "Tu colección" (Nombre / Ranking BGG),
  junto al botón que ya invertía el orden A-Z ↔ Z-A — ahora ese mismo
  botón invierte también mejor-ranking-primero ↔ peor-ranking-primero
  cuando el criterio es el ranking. Los juegos sin ranking (no
  vinculados a BGG, o con muy pocos votos para tener puesto) se quedan
  siempre al final de la lista en ambos sentidos, en vez de intercalarse
  como si un "sin ranking" fuera mejor o peor que cualquier número real.
- Traducción al castellano de las categorías de BGG en el selector
  "Género" de "¿A qué jugamos?" (p. ej. "Card Game" → "Juego de
  cartas"). BGG solo las da en inglés, sea cual sea el idioma de la
  app, así que la traducción es solo de cara al usuario: el valor real
  usado para filtrar sigue siendo el nombre en inglés de BGG, y el
  desplegable se ordena alfabéticamente por la etiqueta ya traducida,
  no por el inglés. Una categoría que no esté en la tabla (por ejemplo,
  una escrita a mano en el catálogo) se muestra tal cual en vez de
  desaparecer.
- Esa misma traducción, ahora también en las sugerencias de "Mecánicas"
  y "Género" (antes "Categorías", unificado con el nombre que ya usaba
  el selector del buscador) del formulario de alta/edición — tanto en
  la lista desplegable como en las etiquetas ya añadidas a un juego.
  Se puede buscar escribiendo en español o en inglés indistintamente
  (p. ej. "trabajad" encuentra "Worker Placement" vía su traducción
  "Colocación de trabajadores"). El texto libre para añadir mecánicas o
  géneros que no existan todavía sigue funcionando igual que antes, sin
  restringir a la lista.
- Vínculo visual entre un juego base y sus expansiones, tanto en "Tu
  colección" como en "¿A qué jugamos?": la tarjeta del juego base
  muestra una insignia "+N expansiones" contando cuántas de sus
  expansiones están en la colección (tenidas o deseadas, sin
  distinción), y la tarjeta de cada expansión muestra "Expansión de
  [nombre]" en vez del contador. En el selector de "¿A qué jugamos?"
  las expansiones ya no aparecían como resultado jugable por sí solas
  (no tiene sentido elegirlas sueltas), pero ahora esa ausencia queda
  explicada por el contador en la tarjeta del juego base en vez de ser
  simplemente invisible. El nombre del juego base se resuelve en el
  backend a partir de `base_game_id` para que se vea aunque ese juego
  base no esté en la propia colección del usuario.
- Logotipo "Powered by BGG" (exigido por los términos de uso de la API de
  BGG para cualquier aplicación pública) en el pie de página, visible en
  toda la app y enlazando a boardgamegeek.com. Usa la versión clara u
  oscura del propio logo oficial de BGG según el tema activo de LudoDex.
- Caché de 24 horas (configurable) para las respuestas de BGG sobre un
  mismo juego (mecánicas, categorías, año, edad, ranking, valoración...),
  tanto en la importación por usuario/CSV como en "Rellenar desde BGG".
  Antes cada importación repetía la consulta a BGG para juegos ya
  consultados recientemente (habitual entre distintos usuarios con
  juegos populares en común, o al reimportar la propia colección) - BGG
  pide explícitamente cachear resultados en vez de repetir peticiones
  innecesarias.
- La importación por CSV ya no se salta las expansiones: si hay un token
  de BGG configurado, se importan igual que cualquier otro juego y se
  intentan enlazar con su juego base consultando BGG (misma llamada
  agrupada de 20 en 20 que ya usaba la importación por usuario - probado
  en vivo con un archivo real de 281 juegos, sin problemas de lentitud).
  Cuando el juego base no está en ese mismo archivo, la expansión se
  importa igualmente pero sin enlazar, y se añade un aviso indicándolo
  en el resultado de la importación. Sin token configurado, el
  comportamiento no cambia: se siguen omitiendo por completo, como
  hasta ahora.
- El mensaje final de la importación por CSV ya no menciona expansiones
  omitidas cuando no se ha omitido ninguna (antes decía siempre "(0
  expansiones omitidas por ahora)").
- Selector "Es una expansión de" en el formulario de añadir/editar juego,
  para marcar a mano que un juego es una expansión y de cuál — hasta
  ahora era un dato que solo rellenaban las importaciones de BGG, sin
  ninguna forma de corregirlo o completarlo manualmente. Ofrece los
  juegos de tu propia colección (no todavía el catálogo completo
  compartido entre usuarios), y no permite elegir el propio juego como
  su base.
- El aviso de una expansión importada por CSV que no se ha podido
  enlazar ahora nombra el juego base cuando lo tenemos en caché de una
  consulta anterior a BGG (de cualquier importación o "Rellenar desde
  BGG"), en vez del mensaje genérico de siempre.
- Al escribir a mano una mecánica o género en el formulario de
  añadir/editar juego, si el texto coincide con la traducción al
  español de un término conocido de BGG (p. ej. escribir "Colocación
  de trabajadores"), se guarda directamente como el término original en
  inglés ("Worker Placement"), el mismo que usan las importaciones de
  BGG — así ambas fuentes convergen en la misma etiqueta en vez de
  quedar como dos etiquetas distintas para lo mismo. Solo afecta a los
  términos que ya conocíamos por las tablas de traducción; cualquier
  otro texto se guarda tal cual, como hasta ahora.
- Indicador de carga (el dado girando) durante una importación por
  CSV, igual que ya tenía la importación por usuario — antes solo se
  veía el aviso de texto "no cierres esta pestaña", sin ninguna
  animación mientras se esperaba.

### Corregido

- El título (y el resto del texto) de cada tarjeta de juego podía quedar
  difícil de leer según qué zona de la imagen del juego cayera detrás
  — en casos como "5 More Minutes" el título de la app llegaba a
  solaparse casi por completo con el propio logo de la caja. El
  degradado oscuro ya no se desvanece del todo en la parte alta de la
  tarjeta, y el texto lleva ahora su propia sombra, así que se lee bien
  independientemente de la imagen de fondo. De paso, las tarjetas son
  algo menos altas — antes dejaban una franja de imagen sin ningún
  texto encima, más grande de lo que hacía falta.
- La imagen de cada tarjeta se recortaba centrada verticalmente, lo
  que a menudo dejaba fuera la parte superior de la portada — la zona
  donde suele estar el título o logo del propio juego. Ahora el
  recorte favorece la parte de arriba (sin llegar a pegarse del todo,
  para no perder según qué portadas cuyo logo está más centrado). Se
  probó también un borde blanco a los lados a modo de marco, pero
  quedaba demasiado marcado sobre el tema oscuro y se descartó.
- Reimportar una colección por usuario de BGG ya no borra las mecánicas
  o géneros que hubieras añadido a mano y que BGG no tenga en su propia
  lista para ese juego — antes se reemplazaba la lista entera con la de
  BGG en cada importación, perdiendo silenciosamente cualquier etiqueta
  propia. Ahora se añaden las de BGG sin quitar las que ya hubiera. La
  contrapartida: si BGG deja de reportar una mecánica/género para un
  juego más adelante, esa etiqueta ya no desaparece sola — habría que
  quitarla a mano.
- El selector "Es una expansión de" ofrecía toda la colección, incluidas
  otras expansiones — elegir una de ellas como base habría creado una
  cadena de dos niveles sin sentido (una expansión de otra expansión).
  Ahora solo aparecen los juegos que son de verdad un juego base.

- El importador del CSV de BGG leía las columnas `minplaytime` y
  `maxplaytime` del fichero pero nunca las guardaba, así que la duración
  de cada juego quedaba vacía tras importar por CSV (a diferencia de la
  importación por usuario vía API, que sí la traía) y el filtro de
  duración de "¿A qué jugamos?" no tenía nada con lo que comparar.
- El número de jugadores (mínimo/máximo) al importar el CSV de BGG se
  sacaba únicamente interpretando el "comentario privado" de cada fila
  (p. ej. "Cooperativo - 1/4"), una convención puramente personal del
  propio autor de la app para anotar su colección, no algo que traiga
  ninguna exportación de BGG en general. Cualquier fila sin ese
  formato exacto se importaba sin jugadores en absoluto. Ahora el
  número de jugadores se lee directamente de las columnas propias de
  BGG `minplayers`/`maxplayers`; el comentario privado se sigue usando,
  pero solo como fuente opcional del modo cooperativo/competitivo
  cuando contiene esas palabras, sin generar ya ningún aviso si no las
  reconoce.
- El botón "Buscar" de "¿A qué jugamos?" no hacía nada: los filtros ya
  se aplican al momento sobre un valor reactivo en cuanto se cambian
  (jugadores, duración, modo, categoría...), así que pulsarlo no
  cambiaba el resultado en absoluto. Se retira; el formulario conserva
  el `@submit.prevent` solo para evitar que Intro en el campo de
  jugadores recargue la página.
- Los botones "Editar"/"Quitar" de cada tarjeta en "Tu colección" se
  cortaban en la vista compacta (el ancho de tarjeta más estrecho no
  dejaba sitio para los dos uno junto al otro con su padding habitual,
  y el propio recorte de la tarjeta ocultaba el sobrante en vez de
  dejarlo desbordar). Ahora usan un padding y tamaño de letra más
  pequeños solo en modo compacto; en modo cómodo y escritorio quedan
  igual que antes. Detectado con una captura de la app en el móvil.

## [0.2.0] - 2026-08-12

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
  mismo botón se añade también al bloque de filtros de "¿A qué jugamos?"
  (ahí oculta el modo/campaña en vez de las mecánicas); la lógica se
  extrajo a un composable (`useCollectionDensity`) y a un componente
  (`DensityToggle`) compartidos por las dos vistas, con la preferencia
  común a ambas.
- Aviso ("toast") de confirmación tras añadir, editar o quitar un juego:
  antes, guardar cambios en el formulario de edición redirigía en
  silencio a la colección sin ninguna señal de que se hubiera guardado
  algo, notorio sobre todo cuando el cambio no era visible a simple vista
  en la tarjeta (p. ej. la complejidad). Un mensaje breve ("Cambios
  guardados.", "Juego añadido.", "Juego quitado de tu colección.")
  aparece abajo y se cierra solo a los 3 segundos. Implementado como un
  store (`toast`) y un componente (`ToastNotification`) montado una vez
  en `App.vue`, para que sobreviva a la navegación entre pantallas —
  reutilizable para futuras confirmaciones sin repetir la lógica.
- Botón para eliminar un juego directamente desde su propia página de
  edición (icono de papelera junto al título "Editar juego"), sin tener
  que volver a la colección para quitarlo desde ahí — útil sobre todo
  entrando desde el atajo de edición del selector, que no tiene ningún
  botón de eliminar. Deliberadamente aparte del botón "Guardar cambios"
  (en vez de justo encima o debajo) para que un editar y un eliminar no
  compitan por el mismo gesto.
- Rediseño de las tarjetas de juego en "Tu colección" y "¿A qué
  jugamos?": la portada pasa a ocupar toda la tarjeta como fondo (con un
  degradado oscuro fijo para que el texto se lea igual sobre cualquier
  imagen, independientemente del tema claro/oscuro), en vez de una
  miniatura pequeña en una esquina sobre fondo blanco. Nuevo componente
  compartido `GameCard` que gestiona la imagen, el degradado y el
  fallback (el dado de siempre, ahora centrado) cuando no hay imagen o
  el enlace de BGG ha dejado de funcionar — sustituye a `GameThumbnail`,
  eliminado por quedar sin uso. Las mecánicas dejan de mostrarse en "Tu
  colección" (quedan solo en el formulario de edición): no aportaban
  nada en un vistazo rápido de cientos de tarjetas y no son un criterio
  para decidir qué hacer con un juego ya guardado, a diferencia de las
  etiquetas de modo/campaña del selector, que sí son un criterio de
  decisión y se mantienen.
- Aviso al cerrar o recargar la pestaña mientras se está importando la
  colección (por usuario o por CSV), con el diálogo nativo del
  navegador y un mensaje en pantalla ("No cierres ni recargues esta
  pestaña..."). Navegar entre secciones de la propia app no interrumpe
  ninguna de las dos importaciones (la petición sigue en curso da igual
  qué vista esté montada, y la del CSV va dentro de una única
  transacción, así que nunca queda a medias); el riesgo real es cerrar
  la pestaña o recargar a mitad de la petición, que sí la corta.
- Botón para ordenar "Tu colección" alfabéticamente (A-Z / Z-A, con un
  clic para invertir el orden), aplicado después del buscador — de
  momento solo por nombre, a la espera de si hace falta otro criterio
  más adelante.

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
- La insignia de estado ("Lo tengo"/"Lo quiero") en "Tu colección" se
  estiraba al ancho completo de la tarjeta en vez de ajustarse a su
  propio texto, a diferencia de las etiquetas de modo/campaña del
  selector — heredaba el `align-items: stretch` del contenedor en
  columna del degradado, ya que a diferencia de esas otras etiquetas no
  vive dentro de una fila flex propia.
- El botón de editar en cada resultado de "¿A qué jugamos?" usaba el
  icono de ajustes/tuerca en vez de uno de lápiz, sin relación con la
  acción real ("editar este juego", no "abrir ajustes").
- La opción "Ambos" del modo de juego decía "Ambos (p. ej. por
  equipos)"; ahora "Ambos (Por equipos)".

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
