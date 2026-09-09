import { defineStore } from 'pinia'
import { apiClient } from '@/lib/api'
import type { Game } from './games'

export interface Friend {
  id: number
  name: string
  bgg_username: string | null
  avatar_url: string | null
}

export interface FriendCollectionComparison {
  friend: Friend
  shared: Game[]
  mineOnly: Game[]
  theirsOnly: Game[]
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
  blockedUsers: FriendEntry[]
  loaded: boolean
  loading: boolean
  loadError: boolean
}

export const useFriendsStore = defineStore('friends', {
  state: (): FriendsState => ({
    friends: [],
    incomingRequests: [],
    outgoingRequests: [],
    blockedUsers: [],
    loaded: false,
    loading: false,
    loadError: false,
  }),

  actions: {
    async fetchAll() {
      if (this.loading) return
      this.loading = true
      this.loadError = false

      try {
        const [friendsResponse, requestsResponse, blocksResponse] = await Promise.all([
          apiClient.get('/friends'),
          apiClient.get('/friends/requests'),
          apiClient.get('/friends/blocks'),
        ])

        this.friends = friendsResponse.data.data
        this.incomingRequests = requestsResponse.data.data.incoming
        this.outgoingRequests = requestsResponse.data.data.outgoing
        this.blockedUsers = blocksResponse.data.data
        this.loaded = true
      } catch {
        // Swallowed, not rethrown - see the identical comment in
        // games.ts's fetchAll() for why (fire-and-forget call sites with
        // no try/catch of their own). `loaded` stays false so
        // FriendsView can distinguish "still loading" from "failed" via
        // `loadError`; re-callable to retry.
        this.loadError = true
      } finally {
        this.loading = false
      }
    },

    /** Just the nav badge's own data, not the full friends/blocks fetchAll()
     * - called on every route change (see App.vue), so it needs to stay
     * cheap. Without a periodic/navigation-triggered refetch, `loaded`
     * being true from the very first fetch meant an incoming request that
     * arrived later never showed until a full page reload recreated the
     * Pinia store from scratch (found live: navigating between sections
     * never updated the badge, only an explicit reload did). Silent on
     * failure - the badge just keeps showing whatever it last knew rather
     * than failing navigation over this.
     */
    async refreshIncomingRequests() {
      try {
        const { data } = await apiClient.get('/friends/requests')
        this.incomingRequests = data.data.incoming
        this.outgoingRequests = data.data.outgoing
      } catch {
        // Silent - see docblock above.
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

    /** Devuelve la unión ya clasificada de mi colección y la de
     * `friendId` (solo juegos "owned" en ambos lados - ver
     * FriendCollectionController). No toca el estado del store: quien
     * la llama (el picker) la guarda en un ref local, para no compartir
     * estado con `friendDetail.ts` - esa store es de la página de un
     * amigo concreto, reutilizarla aquí arriesgaría datos obsoletos si
     * se navega entre el picker y esa página. No captura un 404: el
     * picker solo deja elegir amigos ya aceptados de `friends.friends`,
     * así que un 404 sería una carrera rara (te acaban de eliminar como
     * amigo) que se propaga como error genérico en vez de un caso
     * especial. */
    async fetchCollectionComparison(friendId: number): Promise<FriendCollectionComparison> {
      const { data } = await apiClient.get(`/friends/${friendId}/games`)
      return {
        friend: data.data.friend,
        shared: data.data.shared,
        mineOnly: data.data.mine_only,
        theirsOnly: data.data.theirs_only,
      }
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

    /** The backend already deletes any friendship/pending request between
     * the two on block (see BlockService::block()) - `target` (already in
     * hand, same pattern as sendRequest()) is stripped from all three
     * relationship arrays here too, so the UI reflects that immediately
     * instead of waiting on a refetch. */
    async blockUser(target: Friend): Promise<void> {
      const { data } = await apiClient.post('/friends/blocks', { user_id: target.id })

      this.friends = this.friends.filter((entry) => entry.user.id !== target.id)
      this.incomingRequests = this.incomingRequests.filter((entry) => entry.user.id !== target.id)
      this.outgoingRequests = this.outgoingRequests.filter((entry) => entry.user.id !== target.id)
      this.blockedUsers.push({ id: data.data.id, user: target })
    },

    async unblockUser(blockId: number): Promise<void> {
      await apiClient.delete(`/friends/blocks/${blockId}`)

      this.blockedUsers = this.blockedUsers.filter((entry) => entry.id !== blockId)
    },
  },
})
