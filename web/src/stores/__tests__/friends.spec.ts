import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useFriendsStore, type Friend, type FriendEntry } from '@/stores/friends'
import { apiClient } from '@/lib/api'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  }
})

function makeFriend(overrides: Partial<Friend> = {}): Friend {
  return {
    id: 1,
    name: 'Friend One',
    bgg_username: 'friend_one',
    avatar_url: null,
    ...overrides,
  }
}

function makeEntry(overrides: Partial<FriendEntry> = {}): FriendEntry {
  return { id: 10, user: makeFriend(), ...overrides }
}

describe('useFriendsStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(apiClient.get).mockReset()
    vi.mocked(apiClient.post).mockReset()
    vi.mocked(apiClient.delete).mockReset()
  })

  describe('fetchAll', () => {
    it('loads friends, split requests and blocked users in parallel, marking itself loaded', async () => {
      const friend = makeEntry()
      const incoming = makeEntry({ id: 11, user: makeFriend({ id: 2, name: 'Incoming' }) })
      const outgoing = makeEntry({ id: 12, user: makeFriend({ id: 3, name: 'Outgoing' }) })
      const blocked = makeEntry({ id: 13, user: makeFriend({ id: 4, name: 'Blocked' }) })
      vi.mocked(apiClient.get).mockImplementation((url: string) => {
        if (url === '/friends') return Promise.resolve({ data: { data: [friend] } })
        if (url === '/friends/blocks') return Promise.resolve({ data: { data: [blocked] } })
        return Promise.resolve({ data: { data: { incoming: [incoming], outgoing: [outgoing] } } })
      })
      const store = useFriendsStore()

      await store.fetchAll()

      expect(store.friends).toEqual([friend])
      expect(store.incomingRequests).toEqual([incoming])
      expect(store.outgoingRequests).toEqual([outgoing])
      expect(store.blockedUsers).toEqual([blocked])
      expect(store.loaded).toBe(true)
      expect(store.loading).toBe(false)
    })

    it('ignores a second fetchAll call while the first is still in flight', async () => {
      let resolveFriends: (value: unknown) => void = () => {}
      vi.mocked(apiClient.get).mockImplementation((url: string) => {
        if (url === '/friends') {
          return new Promise((resolve) => {
            resolveFriends = resolve
          })
        }
        if (url === '/friends/blocks') return Promise.resolve({ data: { data: [] } })
        return Promise.resolve({ data: { data: { incoming: [], outgoing: [] } } })
      })
      const store = useFriendsStore()

      const first = store.fetchAll()
      const second = store.fetchAll()

      resolveFriends({ data: { data: [] } })
      await Promise.all([first, second])

      expect(apiClient.get).toHaveBeenCalledTimes(3)
    })

    it('swallows the error and sets loadError instead of rejecting, so fire-and-forget callers never see an unhandled rejection', async () => {
      vi.mocked(apiClient.get).mockRejectedValue(new Error('network error'))
      const store = useFriendsStore()

      await expect(store.fetchAll()).resolves.toBeUndefined()

      expect(store.loadError).toBe(true)
      expect(store.loading).toBe(false)
      expect(store.loaded).toBe(false)
    })
  })

  describe('refreshIncomingRequests', () => {
    it('updates incoming and outgoing requests only, not friends/blocks', async () => {
      const incoming = makeEntry({ id: 11, user: makeFriend({ id: 2, name: 'Incoming' }) })
      const outgoing = makeEntry({ id: 12, user: makeFriend({ id: 3, name: 'Outgoing' }) })
      vi.mocked(apiClient.get).mockResolvedValue({
        data: { data: { incoming: [incoming], outgoing: [outgoing] } },
      })
      const store = useFriendsStore()

      await store.refreshIncomingRequests()

      expect(apiClient.get).toHaveBeenCalledTimes(1)
      expect(apiClient.get).toHaveBeenCalledWith('/friends/requests')
      expect(store.incomingRequests).toEqual([incoming])
      expect(store.outgoingRequests).toEqual([outgoing])
    })

    it('swallows the error instead of rejecting, so a call from a router.afterEach hook never becomes an unhandled rejection', async () => {
      vi.mocked(apiClient.get).mockRejectedValue(new Error('network error'))
      const store = useFriendsStore()

      await expect(store.refreshIncomingRequests()).resolves.toBeUndefined()
    })
  })

  describe('search', () => {
    it('searchByEmail sends the email param and returns the match', async () => {
      const friend = makeFriend()
      vi.mocked(apiClient.get).mockResolvedValue({ data: { data: friend } })
      const store = useFriendsStore()

      const result = await store.searchByEmail('friend@example.com')

      expect(apiClient.get).toHaveBeenCalledWith('/friends/search', {
        params: { email: 'friend@example.com' },
      })
      expect(result).toEqual(friend)
    })

    it('searchByBggUsername sends the bgg_username param and returns the match', async () => {
      const friend = makeFriend()
      vi.mocked(apiClient.get).mockResolvedValue({ data: { data: friend } })
      const store = useFriendsStore()

      const result = await store.searchByBggUsername('friend_one')

      expect(apiClient.get).toHaveBeenCalledWith('/friends/search', {
        params: { bgg_username: 'friend_one' },
      })
      expect(result).toEqual(friend)
    })

    it('returns null when nobody matches (or the match is private - indistinguishable on purpose)', async () => {
      vi.mocked(apiClient.get).mockResolvedValue({ data: { data: null } })
      const store = useFriendsStore()

      const result = await store.searchByEmail('nobody@example.com')

      expect(result).toBeNull()
    })
  })

  describe('fetchCollectionComparison', () => {
    it('maps the snake_case response into shared/mineOnly/theirsOnly without touching the store', async () => {
      const friend = makeFriend()
      vi.mocked(apiClient.get).mockResolvedValue({
        data: { data: { friend, shared: ['catan'], mine_only: ['root'], theirs_only: ['azul'] } },
      })
      const store = useFriendsStore()

      const result = await store.fetchCollectionComparison(friend.id)

      expect(apiClient.get).toHaveBeenCalledWith(`/friends/${friend.id}/games`)
      expect(result).toEqual({
        friend,
        shared: ['catan'],
        mineOnly: ['root'],
        theirsOnly: ['azul'],
      })
      expect(store.friends).toEqual([])
    })

    it('propagates a failed request instead of swallowing it', async () => {
      vi.mocked(apiClient.get).mockRejectedValue(new Error('network error'))
      const store = useFriendsStore()

      await expect(store.fetchCollectionComparison(2)).rejects.toThrow('network error')
    })
  })

  describe('sendRequest', () => {
    it('adds the target to outgoingRequests when the request stays pending', async () => {
      const target = makeFriend()
      vi.mocked(apiClient.post).mockResolvedValue({ data: { data: { id: 99, status: 'pending' } } })
      const store = useFriendsStore()

      await store.sendRequest(target)

      expect(apiClient.post).toHaveBeenCalledWith('/friends/requests', { user_id: target.id })
      expect(store.outgoingRequests).toEqual([{ id: 99, user: target }])
      expect(store.friends).toEqual([])
    })

    it('adds the target straight to friends and drops any matching incoming request when auto-accepted', async () => {
      const target = makeFriend({ id: 5, name: 'Mutual' })
      const store = useFriendsStore()
      store.incomingRequests = [makeEntry({ id: 77, user: target })]
      vi.mocked(apiClient.post).mockResolvedValue({
        data: { data: { id: 77, status: 'accepted' } },
      })

      await store.sendRequest(target)

      expect(store.friends).toEqual([{ id: 77, user: target }])
      expect(store.incomingRequests).toEqual([])
    })
  })

  describe('acceptRequest', () => {
    it('moves the request from incomingRequests to friends', async () => {
      const target = makeFriend()
      const store = useFriendsStore()
      store.incomingRequests = [makeEntry({ id: 30, user: target })]
      vi.mocked(apiClient.post).mockResolvedValue({})

      await store.acceptRequest(30)

      expect(apiClient.post).toHaveBeenCalledWith('/friends/requests/30/accept')
      expect(store.incomingRequests).toEqual([])
      expect(store.friends).toEqual([{ id: 30, user: target }])
    })

    it('does nothing to friends if the request id is not found locally', async () => {
      const store = useFriendsStore()
      vi.mocked(apiClient.post).mockResolvedValue({})

      await store.acceptRequest(404)

      expect(store.friends).toEqual([])
    })
  })

  describe('removeRelationship', () => {
    it('removes the entry from whichever of the three arrays currently has it', async () => {
      const store = useFriendsStore()
      store.friends = [makeEntry({ id: 1 })]
      store.incomingRequests = [makeEntry({ id: 2 })]
      store.outgoingRequests = [makeEntry({ id: 3 })]
      vi.mocked(apiClient.delete).mockResolvedValue({})

      await store.removeRelationship(2)

      expect(apiClient.delete).toHaveBeenCalledWith('/friends/requests/2')
      expect(store.friends).toEqual([makeEntry({ id: 1 })])
      expect(store.incomingRequests).toEqual([])
      expect(store.outgoingRequests).toEqual([makeEntry({ id: 3 })])
    })
  })

  describe('blockUser', () => {
    it('strips the target from friends, incoming and outgoing, and adds them to blockedUsers', async () => {
      const target = makeFriend({ id: 9, name: 'Target' })
      const store = useFriendsStore()
      store.friends = [makeEntry({ id: 1, user: target })]
      store.incomingRequests = [makeEntry({ id: 2, user: target })]
      store.outgoingRequests = [makeEntry({ id: 3, user: target })]
      vi.mocked(apiClient.post).mockResolvedValue({ data: { data: { id: 50 } } })

      await store.blockUser(target)

      expect(apiClient.post).toHaveBeenCalledWith('/friends/blocks', { user_id: target.id })
      expect(store.friends).toEqual([])
      expect(store.incomingRequests).toEqual([])
      expect(store.outgoingRequests).toEqual([])
      expect(store.blockedUsers).toEqual([{ id: 50, user: target }])
    })

    it('only removes entries for the blocked target, not other relationships', async () => {
      const target = makeFriend({ id: 9, name: 'Target' })
      const other = makeFriend({ id: 8, name: 'Other' })
      const store = useFriendsStore()
      store.friends = [makeEntry({ id: 1, user: target }), makeEntry({ id: 2, user: other })]
      vi.mocked(apiClient.post).mockResolvedValue({ data: { data: { id: 50 } } })

      await store.blockUser(target)

      expect(store.friends).toEqual([makeEntry({ id: 2, user: other })])
    })
  })

  describe('unblockUser', () => {
    it('removes the entry from blockedUsers', async () => {
      const store = useFriendsStore()
      store.blockedUsers = [makeEntry({ id: 50 })]
      vi.mocked(apiClient.delete).mockResolvedValue({})

      await store.unblockUser(50)

      expect(apiClient.delete).toHaveBeenCalledWith('/friends/blocks/50')
      expect(store.blockedUsers).toEqual([])
    })
  })
})
