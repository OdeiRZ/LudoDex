import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { apiClient } from '@/lib/api'
import { prefetchBootstrap, resetBootstrap } from '@/lib/bootstrap'
import { useAuthStore } from '@/stores/auth'
import { useFriendsStore } from '@/stores/friends'
import { useGamesStore } from '@/stores/games'
import { makeEntry } from '@/stores/__tests__/gameFixtures'

// Los tres stores recogen su parte de UNA petición de arranque (lib/bootstrap.ts)
// en lugar de pedir cada uno lo suyo; sin ella, piden como siempre.

// Sin importOriginal a propósito: cargar el api real arrastra el router y los stores,
// que importan lib/bootstrap (un ciclo inofensivo en la app, pero que en el test
// dejaba a bootstrap.ts con el cliente HTTP de verdad en vez del simulado).
vi.mock('@/lib/api', () => ({
  getStoredToken: () => 'token-de-prueba-123456',
  storeToken: vi.fn(),
  clearStoredToken: vi.fn(),
  apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

const friend = { id: 2, name: 'Ana', bgg_username: null, avatar_url: null }

function bootstrapResponse() {
  return {
    status: 200,
    headers: { etag: '"v1"' },
    data: {
      user: { id: 1, name: 'Odei', email: 'o@example.com' },
      games: [makeEntry({ name: 'Catan' })],
      mechanics: [{ id: 1, name: 'Trading' }],
      categories: [{ id: 1, name: 'Negotiation' }],
      friends: [{ id: 10, user: friend }],
      requests: { incoming: [{ id: 11, user: friend }], outgoing: [] },
      blocks: [],
    },
  }
}

function urlsAsked() {
  return vi.mocked(apiClient.get).mock.calls.map((call) => call[0])
}

describe('stores reading the bootstrap request', () => {
  beforeEach(() => {
    localStorage.clear()
    resetBootstrap()
    setActivePinia(createPinia())
    vi.mocked(apiClient.get).mockReset()
    vi.mocked(apiClient.get).mockResolvedValue(bootstrapResponse())
    localStorage.setItem('ludodex_token', 'token-de-prueba-123456')
  })

  it('fills user, friends and games from one request, with no separate calls', async () => {
    const auth = useAuthStore()
    const friends = useFriendsStore()
    const games = useGamesStore()
    auth.token = 'token-de-prueba-123456'

    prefetchBootstrap()
    await Promise.all([auth.fetchCurrentUser(), friends.fetchAll(), games.fetchAll()])

    expect(urlsAsked()).toEqual(['/bootstrap'])
    expect(auth.user?.name).toBe('Odei')
    expect(friends.friends).toHaveLength(1)
    expect(friends.incomingRequests).toHaveLength(1)
    expect(friends.loaded).toBe(true)
    expect(games.collection[0]?.game.name).toBe('Catan')
    expect(games.mechanicOptions).toEqual(['Trading'])
    expect(games.categoryOptions).toEqual(['Negotiation'])
    expect(games.loaded).toBe(true)
  })

  it('asks for its own data as always when nothing was prefetched', async () => {
    const games = useGamesStore()
    vi.mocked(apiClient.get).mockImplementation((url: string) =>
      Promise.resolve({ data: { data: url === '/games' ? [makeEntry({ name: 'Root' })] : [] } }),
    )

    await games.fetchAll()

    expect(urlsAsked().sort()).toEqual(['/categories', '/games', '/mechanics'])
    expect(games.collection[0]?.game.name).toBe('Root')
  })

  it('uses the shortcut only once: a later refresh goes to the network for fresh data', async () => {
    const games = useGamesStore()
    prefetchBootstrap()
    await games.fetchAll()
    vi.mocked(apiClient.get).mockReset()
    vi.mocked(apiClient.get).mockImplementation((url: string) =>
      Promise.resolve({ data: { data: url === '/games' ? [makeEntry({ name: 'Nuevo' })] : [] } }),
    )

    await games.fetchAll() // p. ej. tras importar de BGG

    expect(urlsAsked()).toContain('/games')
    expect(games.collection[0]?.game.name).toBe('Nuevo')
  })

  it('falls back to its own requests when the bootstrap request fails', async () => {
    vi.mocked(apiClient.get).mockImplementation((url: string) => {
      if (url === '/bootstrap') return Promise.reject(new Error('network error'))
      return Promise.resolve({ data: { data: url === '/games' ? [makeEntry({ name: 'Root' })] : [] } })
    })
    const games = useGamesStore()

    prefetchBootstrap()
    await games.fetchAll()

    expect(urlsAsked()).toContain('/games')
    expect(games.loaded).toBe(true)
  })

  it('does not ask for the friend requests again right after loading them', async () => {
    const friends = useFriendsStore()
    prefetchBootstrap()
    await friends.fetchAll()
    vi.mocked(apiClient.get).mockClear()

    await friends.refreshIncomingRequests() // el primer cambio de ruta tras abrir la app

    expect(apiClient.get).not.toHaveBeenCalled()
  })

  it('asks for the friend requests again once they are not fresh any more', async () => {
    vi.useFakeTimers()
    const friends = useFriendsStore()
    prefetchBootstrap()
    await friends.fetchAll()
    vi.mocked(apiClient.get).mockReset()
    vi.mocked(apiClient.get).mockResolvedValue({ data: { data: { incoming: [], outgoing: [] } } })

    vi.setSystemTime(Date.now() + 6_000)
    await friends.refreshIncomingRequests()
    vi.useRealTimers()

    expect(urlsAsked()).toEqual(['/friends/requests'])
    expect(friends.incomingRequests).toEqual([])
  })

  it('logging out forgets the pending bootstrap and the copy kept in the browser', async () => {
    const auth = useAuthStore()
    prefetchBootstrap()
    await useGamesStore().fetchAll()
    expect(localStorage.getItem('ludodex.bootstrap')).not.toBeNull()

    auth.clearSession()

    expect(localStorage.getItem('ludodex.bootstrap')).toBeNull()
  })
})
