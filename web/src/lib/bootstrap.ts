import { apiClient, getStoredToken } from '@/lib/api'
import type { User } from '@/stores/auth'
import type { FriendEntry } from '@/stores/friends'
import type { UserGame } from '@/stores/games'

// Todo lo que la app pide al abrirse (usuario, colección, catálogos, amigos,
// solicitudes y bloqueos) en UNA petición, en vez de ocho a la vez. Medido en
// producción: sueltas tardaban ~450 ms y juntas 1,5-2 s (4 trabajadores en la
// CPU mínima de Render), y la lista de juegos no terminaba hasta +3 s.
//
// Es condicional: la última respuesta se guarda en localStorage con su ETag y
// se vuelve a pedir con If-None-Match; si nada ha cambiado el API contesta 304
// sin cuerpo y se usa lo guardado, sin transferir ni reconstruir la colección.
//
// Cómo encaja con los stores: `prefetchBootstrap()` arranca la petición una vez
// por sesión (App.vue) y cada store (auth, friends, games) recoge SU parte con
// `takeBootstrap()` en su propio fetch de siempre. Si no hay respuesta (falló,
// ya se recogió esa parte, o pasaron más de 30 s), devuelve null y el store hace
// su petición normal: es un atajo, nunca el único camino.

export const BOOTSTRAP_KEY = 'ludodex.bootstrap'
const BOOTSTRAP_VERSION = 1
/** Pasado este tiempo la respuesta ya no se reparte: un refresco posterior pide datos nuevos. */
export const BOOTSTRAP_FRESH_MS = 30_000

export type BootstrapSlice = 'user' | 'friends' | 'games'

export interface BootstrapData {
  user: User
  games: UserGame[]
  mechanics: { id: number; name: string }[]
  categories: { id: number; name: string }[]
  friends: FriendEntry[]
  requests: { incoming: FriendEntry[]; outgoing: FriendEntry[] }
  blocks: FriendEntry[]
}

interface Stored {
  v: number
  /** Final del token de la sesión que la guardó (no el token entero). */
  tokenTail: string
  etag: string
  body: BootstrapData
}

interface Loaded {
  body: BootstrapData
  at: number
  taken: Set<BootstrapSlice>
}

type KeyValueStorage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>

function defaultStorage(): KeyValueStorage | null {
  try {
    return window.localStorage
  } catch {
    return null
  }
}

function tokenTail(token: string): string {
  return token.slice(-12)
}

function loadStored(token: string, storage: KeyValueStorage | null): Stored | null {
  try {
    const raw = storage?.getItem(BOOTSTRAP_KEY)
    if (!raw) return null

    const parsed = JSON.parse(raw) as Stored
    if (
      parsed.v !== BOOTSTRAP_VERSION ||
      parsed.tokenTail !== tokenTail(token) ||
      typeof parsed.etag !== 'string' ||
      !Array.isArray(parsed.body?.games)
    ) {
      return null
    }

    return parsed
  } catch {
    return null
  }
}

function saveStored(token: string, etag: string, body: BootstrapData, storage: KeyValueStorage | null) {
  try {
    const stored: Stored = { v: BOOTSTRAP_VERSION, tokenTail: tokenTail(token), etag, body }
    storage?.setItem(BOOTSTRAP_KEY, JSON.stringify(stored))
  } catch {
    // Cuota llena o sin almacenamiento: funciona igual, solo sin el atajo del 304.
  }
}

async function requestBootstrap(token: string): Promise<BootstrapData | null> {
  const storage = defaultStorage()
  const stored = loadStored(token, storage)

  const response = await apiClient.get('/bootstrap', {
    headers: stored ? { 'If-None-Match': stored.etag } : undefined,
    validateStatus: (status) => (status >= 200 && status < 300) || status === 304,
  })

  // 304: nada ha cambiado desde la última vez, lo guardado sigue valiendo. Sin nada
  // guardado no debería pasar; null deja que cada store haga su petición normal.
  if (response.status === 304) return stored?.body ?? null

  const etag = response.headers?.etag
  if (typeof etag === 'string') saveStored(token, etag, response.data, storage)

  return response.data
}

let pending: Promise<Loaded | null> | null = null

/** Arranca la petición de arranque si no hay ya una en curso o reciente. Nunca lanza. */
export function prefetchBootstrap(): void {
  const token = getStoredToken()
  if (!token || pending) return

  pending = requestBootstrap(token).then(
    (body) => (body ? { body, at: Date.now(), taken: new Set<BootstrapSlice>() } : null),
    () => null,
  )
}

/**
 * La respuesta de arranque, UNA vez por parte (user, friends, games), o null si
 * no hay (no se pidió, falló, esa parte ya se recogió o pasó demasiado tiempo):
 * quien la llama debe entonces hacer su petición de siempre.
 */
export async function takeBootstrap(slice: BootstrapSlice): Promise<BootstrapData | null> {
  if (!pending) return null

  const loaded = await pending
  if (!loaded || loaded.taken.has(slice)) return null

  if (Date.now() - loaded.at > BOOTSTRAP_FRESH_MS) {
    resetBootstrap(false)
    return null
  }

  loaded.taken.add(slice)
  if (loaded.taken.size === 3) pending = null

  return loaded.body
}

/** Olvida la petición en curso y, al cerrar sesión, también la copia guardada. */
export function resetBootstrap(clearStored = true): void {
  pending = null

  if (!clearStored) return

  try {
    defaultStorage()?.removeItem(BOOTSTRAP_KEY)
  } catch {
    // nada que limpiar
  }
}
