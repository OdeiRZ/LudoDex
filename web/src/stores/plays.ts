import { defineStore } from 'pinia'
import { apiClient } from '@/lib/api'

export interface Play {
  id: string
  played_at: string
  quantity: number
  duration_minutes: number | null
  game: {
    id: string
    bgg_id: number | null
    name: string
    image_url: string | null
    description: string | null
    description_es: string | null
  }
}

export interface PlaysImportResult {
  imported_count: number
}

interface PlaysState {
  entries: Play[]
  loaded: boolean
  loading: boolean
  currentPage: number
  lastPage: number
  search: string
}

export const usePlaysStore = defineStore('plays', {
  state: (): PlaysState => ({
    entries: [],
    loaded: false,
    loading: false,
    currentPage: 1,
    lastPage: 1,
    search: '',
  }),

  actions: {
    /** Page 1 replaces the list (a fresh load, a refresh after importing,
     * or a new search term); later pages append, driving a "load more"
     * button rather than traditional pagination controls. Always sends
     * the currently active search term (empty string is fine - the
     * backend treats a blank ?search= the same as omitting it), so
     * "load more" keeps filtering by whatever the user last searched for
     * without needing to pass it in again itself. */
    async fetchPage(page = 1) {
      this.loading = true

      try {
        const { data } = await apiClient.get('/plays', { params: { page, search: this.search } })
        this.entries = page === 1 ? data.data : [...this.entries, ...data.data]
        this.currentPage = data.meta.current_page
        this.lastPage = data.meta.last_page
        this.loaded = true
      } finally {
        this.loading = false
      }
    },

    /** Sets the active search term and reloads from page 1 - a new term
     * needs a fresh fetch (not a client-side filter over what's already
     * loaded), since a match could easily be sitting on a page that
     * was never requested. */
    async setSearch(term: string) {
      this.search = term
      await this.fetchPage(1)
    },

    /** Synchronous on the backend (unlike the collection import, /plays has
     * no BGG-side async export step to poll) - resolves with the final
     * result directly, same as importBggCsv(). */
    async importPlays(username: string): Promise<PlaysImportResult> {
      const { data } = await apiClient.post('/bgg-plays-imports', { bgg_username: username })
      return data.data
    },
  },
})
