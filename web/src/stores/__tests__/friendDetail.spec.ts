import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useFriendDetailStore } from '@/stores/friendDetail'
import { apiClient } from '@/lib/api'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  }
})

function notFoundError() {
  return { isAxiosError: true, response: { status: 404 } }
}

describe('useFriendDetailStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(apiClient.get).mockReset()
  })

  describe('fetchCollection', () => {
    it('stores friend, shared, mineOnly and theirsOnly, marking itself loaded', async () => {
      const friend = { id: 2, name: 'Friend One', bgg_username: null, avatar_url: null }
      const shared = [{ id: 'g1', name: 'Catan' }]
      const mineOnly = [{ id: 'g2', name: 'Wingspan' }]
      const theirsOnly = [{ id: 'g3', name: 'Azul' }]
      vi.mocked(apiClient.get).mockResolvedValue({
        data: { data: { friend, shared, mine_only: mineOnly, theirs_only: theirsOnly } },
      })
      const store = useFriendDetailStore()

      await store.fetchCollection(2)

      expect(apiClient.get).toHaveBeenCalledWith('/friends/2/games')
      expect(store.friend).toEqual(friend)
      expect(store.shared).toEqual(shared)
      expect(store.mineOnly).toEqual(mineOnly)
      expect(store.theirsOnly).toEqual(theirsOnly)
      expect(store.collectionLoaded).toBe(true)
      expect(store.collectionLoading).toBe(false)
    })

    it('marks notFound on a 404 without throwing', async () => {
      vi.mocked(apiClient.get).mockRejectedValue(notFoundError())
      const store = useFriendDetailStore()

      await store.fetchCollection(999)

      expect(store.notFound).toBe(true)
      expect(store.collectionLoading).toBe(false)
    })

    it('rethrows any error that is not a 404', async () => {
      vi.mocked(apiClient.get).mockRejectedValue(new Error('network error'))
      const store = useFriendDetailStore()

      await expect(store.fetchCollection(2)).rejects.toThrow('network error')
      expect(store.notFound).toBe(false)
    })
  })

  describe('resetIfDifferentFriend', () => {
    it('clears state and adopts the new friendId when it differs', async () => {
      vi.mocked(apiClient.get).mockResolvedValue({
        data: { data: { friend: {}, shared: [{ id: 'g1' }], mine_only: [], theirs_only: [] } },
      })
      const store = useFriendDetailStore()
      await store.fetchCollection(2)

      vi.mocked(apiClient.get).mockResolvedValue({
        data: { data: { friend: {}, shared: [], mine_only: [], theirs_only: [] } },
      })
      await store.fetchCollection(3)

      expect(store.friendId).toBe(3)
      expect(store.shared).toEqual([])
    })

    it('does nothing when the friendId is unchanged', () => {
      const store = useFriendDetailStore()
      store.friendId = 2
      store.playsSearch = 'catan'

      store.resetIfDifferentFriend(2)

      expect(store.playsSearch).toBe('catan')
    })
  })

  describe('fetchPlays', () => {
    it('replaces entries on page 1 and stores pagination info', async () => {
      const play = { id: 'p1', played_at: '2026-01-01' }
      const friend = { id: 2, name: 'Friend One' }
      vi.mocked(apiClient.get).mockResolvedValue({
        data: { data: [play], friend, meta: { current_page: 1, last_page: 2 } },
      })
      const store = useFriendDetailStore()

      await store.fetchPlays(2, 1)

      expect(apiClient.get).toHaveBeenCalledWith('/friends/2/plays', {
        params: { page: 1, search: '' },
      })
      expect(store.plays).toEqual([play])
      expect(store.friend).toEqual(friend)
      expect(store.playsCurrentPage).toBe(1)
      expect(store.playsLastPage).toBe(2)
      expect(store.playsLoaded).toBe(true)
    })

    it('appends entries instead of replacing them on a page after the first', async () => {
      const store = useFriendDetailStore()
      store.friendId = 2
      store.plays = [{ id: 'p1' } as never]

      vi.mocked(apiClient.get).mockResolvedValue({
        data: { data: [{ id: 'p2' }], friend: {}, meta: { current_page: 2, last_page: 2 } },
      })

      await store.fetchPlays(2, 2)

      expect(store.plays).toEqual([{ id: 'p1' }, { id: 'p2' }])
    })

    it('marks notFound on a 404 without throwing', async () => {
      vi.mocked(apiClient.get).mockRejectedValue(notFoundError())
      const store = useFriendDetailStore()

      await store.fetchPlays(999, 1)

      expect(store.notFound).toBe(true)
    })

    it('ignores a second fetchPlays call while the first is still in flight', async () => {
      let resolveFirst: (value: unknown) => void = () => {}
      vi.mocked(apiClient.get).mockImplementation(
        () =>
          new Promise((resolve) => {
            resolveFirst = resolve
          }),
      )
      const store = useFriendDetailStore()

      const first = store.fetchPlays(2, 1)
      const second = store.fetchPlays(2, 1)

      resolveFirst({ data: { data: [], friend: {}, meta: { current_page: 1, last_page: 1 } } })
      await Promise.all([first, second])

      expect(apiClient.get).toHaveBeenCalledTimes(1)
    })
  })

  describe('setPlaysSearch', () => {
    it('sets the term and reloads from page 1', async () => {
      vi.mocked(apiClient.get).mockResolvedValue({
        data: { data: [], friend: {}, meta: { current_page: 1, last_page: 1 } },
      })
      const store = useFriendDetailStore()

      await store.setPlaysSearch(2, 'catan')

      expect(store.playsSearch).toBe('catan')
      expect(apiClient.get).toHaveBeenCalledWith('/friends/2/plays', {
        params: { page: 1, search: 'catan' },
      })
    })
  })

  describe('fetchPlaysStats', () => {
    it('stores the aggregated result', async () => {
      const stats = {
        total_plays: 5,
        distinct_games: 2,
        total_minutes: 90,
        duration_known_plays: 5,
        top_played: [],
      }
      vi.mocked(apiClient.get).mockResolvedValue({ data: { data: stats } })
      const store = useFriendDetailStore()

      await store.fetchPlaysStats(2)

      expect(apiClient.get).toHaveBeenCalledWith('/friends/2/plays/stats')
      expect(store.playsStats).toEqual(stats)
    })

    it('marks notFound on a 404 without throwing', async () => {
      vi.mocked(apiClient.get).mockRejectedValue(notFoundError())
      const store = useFriendDetailStore()

      await store.fetchPlaysStats(999)

      expect(store.notFound).toBe(true)
    })
  })
})
