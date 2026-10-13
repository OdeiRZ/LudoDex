import { defineStore } from 'pinia'
import { apiClient } from '@/lib/api'
import { takeBootstrap } from '@/lib/bootstrap'
import { usePlaysStore } from './plays'

export interface Game {
  id: string
  bgg_id: number | null
  base_game_id: string | null
  base_game_name: string | null
  name: string
  image_url: string | null
  // Ausentes en las listas (GET /api/games, bootstrap): la sinopsis completa de
  // BGG era el 55 % del peso de la colección y solo la lee el modal de detalle,
  // que la pide al abrirse (`fetchDescription`). Cuando están, vienen del
  // propio modal o de una traducción hecha en esta sesión.
  description?: string | null
  description_es?: string | null
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

/** Sinopsis de un juego, tal como la sirve GET /api/games/{id}/description. */
export interface GameDescription {
  description: string | null
  description_es: string | null
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
  /** Sinopsis ya pedidas en esta sesión, por id de juego: reabrir un modal no repite la petición. */
  descriptions: Record<string, GameDescription>
  loaded: boolean
  loading: boolean
  loadError: boolean
}

export const useGamesStore = defineStore('games', {
  state: (): GamesState => ({
    collection: [],
    mechanicOptions: [],
    categoryOptions: [],
    descriptions: {},
    loaded: false,
    loading: false,
    loadError: false,
  }),

  actions: {
    // Guards against a fetch already in flight, not just the UI-level
    // disabling that's supposed to prevent a second one - `loaded` alone
    // isn't enough for that, since it only flips true once the first
    // request resolves, leaving a window where a second mount (e.g. a
    // fast Dashboard -> Picker -> Dashboard navigation) can fire its own
    // concurrent request before then (found via a caching audit, not
    // reported directly).
    async fetchAll() {
      if (this.loading) return
      this.loading = true
      this.loadError = false

      try {
        // Atajo: la petición de arranque (lib/bootstrap.ts) ya trae la colección y los
        // catálogos. Sin ella (no se pidió, falló o ya se usó) se piden como siempre.
        const bootstrap = await takeBootstrap('games')
        if (bootstrap) {
          this.collection = bootstrap.games
          this.mechanicOptions = bootstrap.mechanics.map((item) => item.name)
          this.categoryOptions = bootstrap.categories.map((item) => item.name)
          this.loaded = true
          return
        }

        const [gamesResponse, mechanicsResponse, categoriesResponse] = await Promise.all([
          apiClient.get('/games'),
          apiClient.get('/mechanics'),
          apiClient.get('/categories'),
        ])

        this.collection = gamesResponse.data.data
        this.mechanicOptions = mechanicsResponse.data.data.map((item: Catalog) => item.name)
        this.categoryOptions = categoriesResponse.data.data.map((item: Catalog) => item.name)
        this.loaded = true
      } catch {
        // Swallowed, not rethrown: several callers (AddGameView,
        // EditGameView, PickerView) call this fire-and-forget or with a
        // bare `await` with no surrounding try/catch - a rethrow here
        // would turn a network hiccup into an unhandled promise
        // rejection at each of those call sites. `loaded` deliberately
        // stays false so DashboardView can tell "still loading" apart
        // from "failed" via `loadError`, and `fetchAll()` is safely
        // re-callable to retry (loading/loadError both reset at the top
        // of this method).
        this.loadError = true
      } finally {
        this.loading = false
      }
    },

    /** La sinopsis (y su traducción, si existe) de un juego. Pide al API solo la
     * primera vez por sesión; el modal de detalle es el único que la muestra. */
    async fetchDescription(gameId: string): Promise<GameDescription> {
      const cached = this.descriptions[gameId]
      if (cached) return cached

      const { data } = await apiClient.get(`/games/${gameId}/description`)
      const description: GameDescription = {
        description: data.description,
        description_es: data.description_es,
      }
      this.descriptions[gameId] = description

      return description
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
     * since a shared game only ever needs translating once. Also patches
     * any matching entry in the plays store: play.game is its own
     * separately-fetched projection of the same Game (see PlayResource),
     * never part of this collection array, so without this a translation
     * done from Dashboard/Picker never reached Partidas - opening the
     * same game's play-history modal there kept showing English despite
     * the DB already having the translation (found via a caching audit,
     * not reported directly; GameDetailModal's own `translated` event
     * already covers the same gap for a modal already open on Partidas
     * itself, but not one opened there afterwards). Returns the value
     * rather than relying only on the store mutations, so a caller with
     * its own local copy of the game (like the details modal) can update
     * without waiting on either array to re-render it. */
    async translateDescription(gameId: string): Promise<string | null> {
      const { data } = await apiClient.post(`/games/${gameId}/translate-description`)

      const cached = this.descriptions[gameId]
      if (cached) cached.description_es = data.description_es

      this.collection
        .filter((entry) => entry.game.id === gameId)
        .forEach((entry) => {
          entry.game.description_es = data.description_es
        })

      usePlaysStore()
        .entries.filter((play) => play.game.id === gameId)
        .forEach((play) => {
          play.game.description_es = data.description_es
        })

      return data.description_es
    },
  },
})
