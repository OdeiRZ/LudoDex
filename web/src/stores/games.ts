import { defineStore } from 'pinia'
import { apiClient } from '@/lib/api'

export interface Game {
  id: string
  bgg_id: number | null
  base_game_id: string | null
  base_game_name: string | null
  name: string
  image_url: string | null
  description: string | null
  description_es: string | null
  year_published: number | null
  min_age: string | null
  bgg_rank: number | null
  rating: number | null
  min_players: number | null
  max_players: number | null
  min_playtime_minutes: number | null
  max_playtime_minutes: number | null
  weight: number | null
  is_cooperative: boolean
  is_competitive: boolean
  has_campaign: boolean
  mechanics: string[]
  categories: string[]
}

export interface UserGame {
  id: string
  status: 'owned' | 'wishlist'
  game: Game
}

export interface UserGamePayload {
  name: string
  image_url?: string | null
  bgg_id?: number | null
  year_published?: number | null
  min_age?: string | null
  bgg_rank?: number | null
  rating?: number | null
  min_players?: number | null
  max_players?: number | null
  min_playtime_minutes?: number | null
  max_playtime_minutes?: number | null
  weight?: number | null
  is_cooperative: boolean
  is_competitive: boolean
  has_campaign: boolean
  base_game_id?: string | null
  mechanics: string[]
  categories: string[]
  status: 'owned' | 'wishlist'
}

interface Catalog {
  id: number
  name: string
}

export interface BggImportStatus {
  id: string
  bgg_username: string
  status: 'pending' | 'completed' | 'failed'
  imported_count: number | null
  error_message: string | null
}

export interface BggCsvImportResult {
  imported_count: number
  skipped_expansions_count: number
  skipped_no_status_count: number
  warnings: string[]
}

export interface BggGameLookup {
  bgg_id: number
  name: string
  image_url: string | null
  description: string | null
  year_published: number | null
  min_age: string | null
  bgg_rank: number | null
  rating: number | null
  min_players: number | null
  max_players: number | null
  min_playtime_minutes: number | null
  max_playtime_minutes: number | null
  weight: number | null
  mechanics: string[]
  categories: string[]
}

interface GamesState {
  collection: UserGame[]
  mechanicOptions: string[]
  categoryOptions: string[]
  loaded: boolean
  loading: boolean
}

export const useGamesStore = defineStore('games', {
  state: (): GamesState => ({
    collection: [],
    mechanicOptions: [],
    categoryOptions: [],
    loaded: false,
    loading: false,
  }),

  actions: {
    async fetchAll() {
      this.loading = true

      try {
        const [gamesResponse, mechanicsResponse, categoriesResponse] = await Promise.all([
          apiClient.get('/games'),
          apiClient.get('/mechanics'),
          apiClient.get('/categories'),
        ])

        this.collection = gamesResponse.data.data
        this.mechanicOptions = mechanicsResponse.data.data.map((item: Catalog) => item.name)
        this.categoryOptions = categoriesResponse.data.data.map((item: Catalog) => item.name)
        this.loaded = true
      } finally {
        this.loading = false
      }
    },

    async createGame(payload: UserGamePayload) {
      const { data } = await apiClient.post('/games', payload)
      this.collection.unshift(data.data)
    },

    async updateGame(userGameId: string, payload: UserGamePayload) {
      const { data } = await apiClient.put(`/games/${userGameId}`, payload)
      const index = this.collection.findIndex((entry) => entry.id === userGameId)

      if (index !== -1) {
        this.collection[index] = data.data
      }
    },

    async deleteGame(userGameId: string) {
      await apiClient.delete(`/games/${userGameId}`)
      this.collection = this.collection.filter((entry) => entry.id !== userGameId)
    },

    /** Only removes this user's collection entries (status) - the
     * underlying games are a catalog shared with everyone else, so they
     * and other users' collections are untouched. */
    async clearCollection() {
      await apiClient.delete('/games')
      this.collection = []
    },

    async startBggImport(username: string): Promise<BggImportStatus> {
      const { data } = await apiClient.post('/bgg-imports', { bgg_username: username })
      return data.data
    },

    async pollBggImport(id: string): Promise<BggImportStatus> {
      const { data } = await apiClient.get(`/bgg-imports/${id}`)
      return data.data
    },

    async lookupBggGame(bggId: number): Promise<BggGameLookup> {
      const { data } = await apiClient.get(`/bgg-lookup/games/${bggId}`)
      return data.data
    },

    /** Unlike startBggImport, this resolves with the final result directly -
     * parsing a CSV is synchronous, so there's no pending/polling state. */
    async importBggCsv(file: File): Promise<BggCsvImportResult> {
      const form = new FormData()
      form.append('file', file)

      const { data } = await apiClient.post('/bgg-imports/csv', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return data.data
    },

    /** Idempotent on the backend (an already-translated game just echoes
     * description_es back without calling DeepL again), and games is a
     * catalog shared across every user - patches every collection entry
     * for this game (not just the one that happened to trigger it),
     * since a shared game only ever needs translating once. Returns the
     * value rather than relying only on the store mutation, so a caller
     * with its own local copy of the game (like the details modal) can
     * update without waiting on the collection array to re-render it. */
    async translateDescription(gameId: string): Promise<string | null> {
      const { data } = await apiClient.post(`/games/${gameId}/translate-description`)

      this.collection
        .filter((entry) => entry.game.id === gameId)
        .forEach((entry) => {
          entry.game.description_es = data.description_es
        })

      return data.description_es
    },
  },
})
