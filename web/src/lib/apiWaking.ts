import { ref } from 'vue'

// Render (plan gratuito) duerme la API tras ~15 min sin tráfico, y la primera
// petición después tarda unos 35-50 s en contestar (medido: /bootstrap 35,5 s).
// Mientras, la colección solo enseña esqueletos y parece colgada. Esto
// enciende `apiWaking` cuando una petición lleva unos segundos sin respuesta y
// la API no había contestado nada en un buen rato, es decir, cuando casi
// seguro está arrancando. No salta con las peticiones largas normales (una
// importación de BGG) porque la API acaba de contestar otras. Mismo módulo
// que MIRA.

export const SLOW_AFTER_MS = 5000
/** Sin respuestas durante este tiempo, la API puede estar dormida. */
export const IDLE_BEFORE_SLEEP_MS = 10 * 60_000

export const apiWaking = ref(false)

let lastResponseAt: number | null = null
let timer: ReturnType<typeof setTimeout> | null = null

/** Al salir una petición. */
export function requestStarted(now = Date.now()): void {
  const mayBeAsleep = lastResponseAt === null || now - lastResponseAt > IDLE_BEFORE_SLEEP_MS
  if (!mayBeAsleep || timer !== null) return
  timer = setTimeout(() => {
    apiWaking.value = true
  }, SLOW_AFTER_MS)
}

/** Al llegar cualquier respuesta del servidor (también un error HTTP): ya está despierto. */
export function responseArrived(now = Date.now()): void {
  lastResponseAt = now
  if (timer !== null) clearTimeout(timer)
  timer = null
  apiWaking.value = false
}

/** Solo para los tests. */
export function resetApiWaking(): void {
  lastResponseAt = null
  if (timer !== null) clearTimeout(timer)
  timer = null
  apiWaking.value = false
}
