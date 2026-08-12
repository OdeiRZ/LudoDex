import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useGamesStore } from '@/stores/games'
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
    const updated = { ...entry, notes: 'Genial' }
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
})
