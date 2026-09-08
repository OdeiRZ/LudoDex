import { defineStore } from 'pinia'
import { isAxiosError } from 'axios'
import { apiClient } from '@/lib/api'
import type { Friend } from './friends'
import type { Game } from './games'
import type { Play, PlaysStats } from './plays'

interface FriendDetailState {
  friendId: number | null
  friend: Friend | null
  notFound: boolean
  collectionHidden: boolean
  playsHidden: boolean
  shared: Game[]
  mineOnly: Game[]
  theirsOnly: Game[]
  collectionLoading: boolean
  collectionLoaded: boolean
  plays: Play[]
  playsLoading: boolean
  playsLoaded: boolean
  playsCurrentPage: number
  playsLastPage: number
  playsSearch: string
  playsStats: PlaysStats | null
}

export const useFriendDetailStore = defineStore('friendDetail', {
  state: (): FriendDetailState => ({
    friendId: null,
    friend: null,
    notFound: false,
    collectionHidden: false,
    playsHidden: false,
    shared: [],
    mineOnly: [],
    theirsOnly: [],
    collectionLoading: false,
    collectionLoaded: false,
    plays: [],
    playsLoading: false,
    playsLoaded: false,
    playsCurrentPage: 1,
    playsLastPage: 1,
    playsSearch: '',
    playsStats: null,
  }),

  actions: {
    /** Navigating from one friend's detail page to another's without the
     * component unmounting (e.g. via router-link inside this same view)
     * would otherwise leave the previous friend's collection/plays
     * showing while the new ones load. */
    resetIfDifferentFriend(friendId: number) {
      if (this.friendId === friendId) return
      this.$reset()
      this.friendId = friendId
    },

    async fetchCollection(friendId: number) {
      this.resetIfDifferentFriend(friendId)
      this.collectionLoading = true
      try {
        const { data } = await apiClient.get(`/friends/${friendId}/games`)
        this.friend = data.data.friend
        this.shared = data.data.shared
        this.mineOnly = data.data.mine_only
        this.theirsOnly = data.data.theirs_only
        this.collectionLoaded = true
      } catch (err) {
        // A 404 here is never distinguishable between "no such friend" and
        // "exists but isn't an accepted friend" - the backend already
        // guarantees that (see FriendshipService::resolveAcceptedFriend()),
        // so this never tries to show a different message per cause either.
        // A 403 is different: you ARE accepted friends (something this
        // page already knows independently, since you got here via the
        // friends list), but this friend has turned off collection
        // sharing specifically - only THIS section should say so, not the
        // whole page (see collectionHidden vs. notFound, and
        // FriendCollectionController::index()'s own comment on why it's
        // 403 and not 404).
        if (isAxiosError(err) && err.response?.status === 404) {
          this.notFound = true
        } else if (isAxiosError(err) && err.response?.status === 403) {
          this.collectionHidden = true
        } else {
          throw err
        }
      } finally {
        this.collectionLoading = false
      }
    },

    async fetchPlays(friendId: number, page = 1) {
      this.resetIfDifferentFriend(friendId)
      if (this.playsLoading) return
      this.playsLoading = true
      try {
        const { data } = await apiClient.get(`/friends/${friendId}/plays`, {
          params: { page, search: this.playsSearch },
        })
        this.friend = this.friend ?? data.friend
        this.plays = page === 1 ? data.data : [...this.plays, ...data.data]
        this.playsCurrentPage = data.meta.current_page
        this.playsLastPage = data.meta.last_page
        this.playsLoaded = true
      } catch (err) {
        // See fetchCollection()'s own comment for why 403 (playsHidden)
        // and 404 (notFound) are kept separate.
        if (isAxiosError(err) && err.response?.status === 404) {
          this.notFound = true
        } else if (isAxiosError(err) && err.response?.status === 403) {
          this.playsHidden = true
        } else {
          throw err
        }
      } finally {
        this.playsLoading = false
      }
    },

    async setPlaysSearch(friendId: number, term: string) {
      // resetIfDifferentFriend() first, not after: fetchPlays() below also
      // calls it, and if this is the very first call for this friendId
      // (friendId not set yet) that reset would otherwise wipe the search
      // term right back out immediately after setting it.
      this.resetIfDifferentFriend(friendId)
      this.playsSearch = term
      await this.fetchPlays(friendId, 1)
    },

    async fetchPlaysStats(friendId: number) {
      try {
        const { data } = await apiClient.get(`/friends/${friendId}/plays/stats`)
        this.playsStats = data.data
      } catch (err) {
        if (isAxiosError(err) && err.response?.status === 404) {
          this.notFound = true
        } else if (isAxiosError(err) && err.response?.status === 403) {
          this.playsHidden = true
        } else {
          throw err
        }
      }
    },
  },
})
