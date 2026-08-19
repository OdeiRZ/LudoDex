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
    game: { id: 'game-1', bgg_id: 13, name: 'Catan', image_url: null },
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
    expect(apiClient.post).toHaveBeenCalledWith('/bgg-plays-imports', { bgg_username: 'odei' })
    expect(store.entries).toEqual([])
  })
})
