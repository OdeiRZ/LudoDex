import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useGamesStore } from '@/stores/games'
import { usePlaysStore } from '@/stores/plays'
import { apiClient } from '@/lib/api'
import { makeEntry } from '@/stores/__tests__/gameFixtures'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  }
})

describe('useGamesStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(apiClient.get).mockReset()
    vi.mocked(apiClient.post).mockReset()
    vi.mocked(apiClient.put).mockReset()
    vi.mocked(apiClient.delete).mockReset()
  })

  it('fetches the collection and catalogs in parallel, marking itself loaded', async () => {
    const entry = makeEntry({ name: 'Root' })
    vi.mocked(apiClient.get).mockImplementation((url: string) => {
      if (url === '/games') return Promise.resolve({ data: { data: [entry] } })
      if (url === '/mechanics') return Promise.resolve({ data: { data: [{ id: 1, name: 'Drafting' }] } })
      if (url === '/categories') return Promise.resolve({ data: { data: [{ id: 1, name: 'Bluffing' }] } })
      throw new Error(`unexpected url ${url}`)
    })
    const store = useGamesStore()

    await store.fetchAll()

    expect(store.collection).toEqual([entry])
    expect(store.mechanicOptions).toEqual(['Drafting'])
    expect(store.categoryOptions).toEqual(['Bluffing'])
    expect(store.loaded).toBe(true)
    expect(store.loading).toBe(false)
  })

  it('ignores a second fetchAll call while the first is still in flight', async () => {
    // A single shared pending promise, not a new one per call - fetchAll
    // fires three concurrent apiClient.get calls (games/mechanics/
    // categories) via Promise.all, so resolving only the most recently
    // created promise (as a naive `mockImplementation` capturing its own
    // resolver each time would) would leave the other two stuck forever.
    let resolveFetch: (value: unknown) => void = () => {}
    const pending = new Promise((resolve) => { resolveFetch = resolve })
    vi.mocked(apiClient.get).mockImplementation(() => pending)
    const store = useGamesStore()

    // e.g. a fast Dashboard -> Picker -> Dashboard navigation, each
    // mount's own `if (!loaded) fetchAll()` guard firing before the
    // first request has resolved.
    const first = store.fetchAll()
    const second = store.fetchAll()

    resolveFetch({ data: { data: [] } })
    await Promise.all([first, second])

    expect(apiClient.get).toHaveBeenCalledTimes(3) // /games, /mechanics, /categories - once, not twice
  })

  it('turns loading off even when fetchAll fails', async () => {
    vi.mocked(apiClient.get).mockRejectedValue(new Error('network error'))
    const store = useGamesStore()

    await expect(store.fetchAll()).rejects.toThrow('network error')

    expect(store.loading).toBe(false)
    expect(store.loaded).toBe(false)
  })

  it('prepends a newly created game to the collection', async () => {
    const existing = makeEntry({ name: 'Root' })
    const created = makeEntry({ name: 'Ark Nova' })
    const store = useGamesStore()
    store.collection = [existing]
    vi.mocked(apiClient.post).mockResolvedValue({ data: { data: created } })

    await store.createGame({
      name: 'Ark Nova',
      is_cooperative: false,
      is_competitive: true,
      has_campaign: false,
      mechanics: [],
      categories: [],
      status: 'owned',
    })

    expect(store.collection).toEqual([created, existing])
  })

  it('replaces the matching entry in place when updating a game', async () => {
    const entry = makeEntry({ name: 'Root' })
    const other = makeEntry({ name: 'Ark Nova' })
    const updated = { ...entry, status: 'wishlist' as const }
    const store = useGamesStore()
    store.collection = [entry, other]
    vi.mocked(apiClient.put).mockResolvedValue({ data: { data: updated } })

    await store.updateGame(entry.id, {
      name: 'Root',
      is_cooperative: false,
      is_competitive: false,
      has_campaign: false,
      mechanics: [],
      categories: [],
      status: 'owned',
    })

    expect(store.collection).toEqual([updated, other])
  })

  it('removes the game from the collection after deleting it', async () => {
    const entry = makeEntry({ name: 'Root' })
    const other = makeEntry({ name: 'Ark Nova' })
    const store = useGamesStore()
    store.collection = [entry, other]
    vi.mocked(apiClient.delete).mockResolvedValue({ data: null })

    await store.deleteGame(entry.id)

    expect(store.collection).toEqual([other])
  })

  it('empties the collection after clearing it', async () => {
    const store = useGamesStore()
    store.collection = [makeEntry({ name: 'Root' }), makeEntry({ name: 'Ark Nova' })]
    vi.mocked(apiClient.delete).mockResolvedValue({ data: null })

    await store.clearCollection()

    expect(apiClient.delete).toHaveBeenCalledWith('/games')
    expect(store.collection).toEqual([])
  })

  it('patches description_es onto every collection entry for the translated game', async () => {
    const root = makeEntry({ id: 'g1', description: 'A game about woodland factions.' })
    const otherGame = makeEntry({ id: 'g2', description: 'Something else.' })
    const store = useGamesStore()
    store.collection = [root, otherGame]
    vi.mocked(apiClient.post).mockResolvedValue({ data: { description_es: 'Un juego sobre facciones del bosque.' } })

    const result = await store.translateDescription('g1')

    expect(apiClient.post).toHaveBeenCalledWith('/games/g1/translate-description')
    expect(result).toBe('Un juego sobre facciones del bosque.')
    expect(store.collection[0].game.description_es).toBe('Un juego sobre facciones del bosque.')
    expect(store.collection[1].game.description_es).toBeNull()
  })

  // Regression test: play.game is its own separately-fetched projection
  // of the same Game, never part of `collection` - without patching it
  // too, translating from Dashboard/Picker never reached a play's own
  // detail modal on Partidas (found via a caching audit, not reported
  // directly).
  it('also patches description_es onto any matching entry in the plays store', async () => {
    const root = makeEntry({ id: 'g1', description: 'A game about woodland factions.' })
    const store = useGamesStore()
    store.collection = [root]
    vi.mocked(apiClient.post).mockResolvedValue({ data: { description_es: 'Un juego sobre facciones del bosque.' } })

    const plays = usePlaysStore()
    plays.entries = [
      {
        id: 'play-1',
        played_at: '2026-01-01',
        quantity: 1,
        duration_minutes: null,
        game: { id: 'g1', bgg_id: null, name: 'Root', image_url: null, description: null, description_es: null },
      },
      {
        id: 'play-2',
        played_at: '2026-01-01',
        quantity: 1,
        duration_minutes: null,
        game: { id: 'g2', bgg_id: null, name: 'Other', image_url: null, description: null, description_es: null },
      },
    ]

    await store.translateDescription('g1')

    expect(plays.entries[0].game.description_es).toBe('Un juego sobre facciones del bosque.')
    expect(plays.entries[1].game.description_es).toBeNull()
  })
})
