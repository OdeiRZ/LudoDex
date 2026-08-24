import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { usePlaysStore, type Play } from '@/stores/plays'
import { apiClient } from '@/lib/api'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  }
})

function makePlay(overrides: Partial<Play> = {}): Play {
  return {
    id: 'play-1',
    played_at: '2026-01-01',
    quantity: 1,
    duration_minutes: 30,
    game: {
      id: 'game-1',
      bgg_id: 13,
      name: 'Catan',
      image_url: null,
      description: null,
      description_es: null,
      base_game_name: null,
    },
    ...overrides,
  }
}

describe('usePlaysStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(apiClient.get).mockReset()
    vi.mocked(apiClient.post).mockReset()
  })

  it('replaces entries on page 1, marking itself loaded', async () => {
    const play = makePlay()
    vi.mocked(apiClient.get).mockResolvedValue({
      data: { data: [play], meta: { current_page: 1, last_page: 3 } },
    })
    const store = usePlaysStore()

    await store.fetchPage(1)

    expect(store.entries).toEqual([play])
    expect(store.currentPage).toBe(1)
    expect(store.lastPage).toBe(3)
    expect(store.loaded).toBe(true)
    expect(store.loading).toBe(false)
  })

  it('appends entries instead of replacing them on a page after the first', async () => {
    const first = makePlay({ id: 'play-1' })
    const second = makePlay({ id: 'play-2' })
    const store = usePlaysStore()
    store.entries = [first]

    vi.mocked(apiClient.get).mockResolvedValue({
      data: { data: [second], meta: { current_page: 2, last_page: 2 } },
    })

    await store.fetchPage(2)

    expect(store.entries).toEqual([first, second])
    expect(store.currentPage).toBe(2)
  })

  it('sends the currently active search term (empty by default) alongside every page request', async () => {
    vi.mocked(apiClient.get).mockResolvedValue({
      data: { data: [], meta: { current_page: 1, last_page: 1 } },
    })
    const store = usePlaysStore()

    await store.fetchPage(1)

    expect(apiClient.get).toHaveBeenCalledWith('/plays', { params: { page: 1, search: '' } })
  })

  it('setSearch sets the term and reloads from page 1, replacing whatever was there', async () => {
    const store = usePlaysStore()
    store.entries = [makePlay({ id: 'stale' })]
    store.currentPage = 3

    const fresh = makePlay({ id: 'fresh' })
    vi.mocked(apiClient.get).mockResolvedValue({
      data: { data: [fresh], meta: { current_page: 1, last_page: 1 } },
    })

    await store.setSearch('catan')

    expect(store.search).toBe('catan')
    expect(apiClient.get).toHaveBeenCalledWith('/plays', { params: { page: 1, search: 'catan' } })
    expect(store.entries).toEqual([fresh])
  })

  it('ignores a second fetchPage call while the first is still in flight', async () => {
    let resolveFirst: (value: unknown) => void = () => {}
    vi.mocked(apiClient.get).mockImplementation(
      () => new Promise((resolve) => { resolveFirst = resolve }),
    )
    const store = usePlaysStore()

    const first = store.fetchPage(1)
    const second = store.fetchPage(1) // e.g. a fast double-click on "load more"

    resolveFirst({ data: { data: [], meta: { current_page: 1, last_page: 1 } } })
    await Promise.all([first, second])

    expect(apiClient.get).toHaveBeenCalledTimes(1)
  })

  it('turns loading off even when fetchPage fails', async () => {
    vi.mocked(apiClient.get).mockRejectedValue(new Error('network error'))
    const store = usePlaysStore()

    await expect(store.fetchPage(1)).rejects.toThrow('network error')

    expect(store.loading).toBe(false)
    expect(store.loaded).toBe(false)
  })

  it('resolves importPlays with the final result directly, without touching entries', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({ data: { data: { imported_count: 42 } } })
    const store = usePlaysStore()

    const result = await store.importPlays('odei')

    expect(result).toEqual({ imported_count: 42 })
    expect(apiClient.post).toHaveBeenCalledWith('/bgg-plays-imports', { bgg_username: 'odei', full: false })
    expect(store.entries).toEqual([])
  })

  it('sends full: true when asked for a full reimport', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({ data: { data: { imported_count: 42 } } })
    const store = usePlaysStore()

    await store.importPlays('odei', true)

    expect(apiClient.post).toHaveBeenCalledWith('/bgg-plays-imports', { bgg_username: 'odei', full: true })
  })

  it('fetchStats stores the aggregated result from the backend', async () => {
    const stats = {
      total_plays: 10,
      distinct_games: 4,
      total_minutes: 300,
      duration_known_plays: 9,
      top_played: [{ game: { id: 'game-1', name: 'Catan', image_url: null }, count: 5 }],
    }
    vi.mocked(apiClient.get).mockResolvedValue({ data: { data: stats } })
    const store = usePlaysStore()

    await store.fetchStats()

    expect(apiClient.get).toHaveBeenCalledWith('/plays/stats')
    expect(store.stats).toEqual(stats)
  })
})
