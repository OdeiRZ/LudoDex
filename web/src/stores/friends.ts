import { defineStore } from 'pinia'
import { apiClient } from '@/lib/api'

export interface Friend {
  id: number
  name: string
  bgg_username: string | null
  avatar_url: string | null
}

/** Shared shape for a friend entry and a pending-request entry - both are
 * just a friendship row id plus the other person's public info. */
export interface FriendEntry {
  id: number
  user: Friend
}

interface FriendsState {
  friends: FriendEntry[]
  incomingRequests: FriendEntry[]
  outgoingRequests: FriendEntry[]
  loaded: boolean
  loading: boolean
}

export const useFriendsStore = defineStore('friends', {
  state: (): FriendsState => ({
    friends: [],
    incomingRequests: [],
    outgoingRequests: [],
    loaded: false,
    loading: false,
  }),

  actions: {
    async fetchAll() {
      if (this.loading) return
      this.loading = true

      try {
        const [friendsResponse, requestsResponse] = await Promise.all([
          apiClient.get('/friends'),
          apiClient.get('/friends/requests'),
        ])

        this.friends = friendsResponse.data.data
        this.incomingRequests = requestsResponse.data.data.incoming
        this.outgoingRequests = requestsResponse.data.data.outgoing
        this.loaded = true
      } finally {
        this.loading = false
      }
    },

    /** Returns null both when nobody matches and when a real account
     * exists but isn't discoverable - the backend deliberately never lets
     * this distinguish the two (see FriendshipService::search()). */
    async searchByEmail(email: string): Promise<Friend | null> {
      const { data } = await apiClient.get('/friends/search', { params: { email } })
      return data.data
    },

    async searchByBggUsername(bggUsername: string): Promise<Friend | null> {
      const { data } = await apiClient.get('/friends/search', {
        params: { bgg_username: bggUsername },
      })
      return data.data
    },

    /** The backend only returns {id, status} - `target` (already in hand
     * from a just-run search) fills in the rest locally instead of a
     * second round trip. When `status` comes back 'accepted' (the target
     * had already sent *us* a pending request), it lands straight in
     * `friends`, and any matching incoming request is dropped instead of
     * staying stuck as a request for a friendship that no longer needs
     * accepting. */
    async sendRequest(target: Friend): Promise<void> {
      const { data } = await apiClient.post('/friends/requests', { user_id: target.id })

      if (data.data.status === 'accepted') {
        this.friends.push({ id: data.data.id, user: target })
        this.incomingRequests = this.incomingRequests.filter((entry) => entry.user.id !== target.id)
      } else {
        this.outgoingRequests.push({ id: data.data.id, user: target })
      }
    },

    async acceptRequest(requestId: number): Promise<void> {
      const request = this.incomingRequests.find((entry) => entry.id === requestId)
      await apiClient.post(`/friends/requests/${requestId}/accept`)

      this.incomingRequests = this.incomingRequests.filter((entry) => entry.id !== requestId)
      if (request) {
        this.friends.push({ id: request.id, user: request.user })
      }
    },

    /** Covers declining an incoming request, cancelling an outgoing one,
     * and unfriending - same endpoint, same effect (the row is gone), so
     * one action removes it from whichever of the three arrays has it. */
    async removeRelationship(friendshipId: number): Promise<void> {
      await apiClient.delete(`/friends/requests/${friendshipId}`)

      this.incomingRequests = this.incomingRequests.filter((entry) => entry.id !== friendshipId)
      this.outgoingRequests = this.outgoingRequests.filter((entry) => entry.id !== friendshipId)
      this.friends = this.friends.filter((entry) => entry.id !== friendshipId)
    },
  },
})
