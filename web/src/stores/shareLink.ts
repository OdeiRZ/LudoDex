import { defineStore } from 'pinia'
import { apiClient } from '@/lib/api'

export interface ShareLink {
  token: string
  /** ISO 8601, o null si no caduca. */
  expires_at: string | null
  expired: boolean
  views_count: number
  last_viewed_at: string | null
}

/** Caducidades que ofrece la pantalla (días); `null` = no caduca. Por defecto, 7. */
export const EXPIRY_OPTIONS: (number | null)[] = [7, 30, 90, null]
export const DEFAULT_EXPIRY_DAYS = 7

interface ShareLinkState {
  link: ShareLink | null
  loaded: boolean
  loading: boolean
  error: boolean
}

/** La dirección pública de un enlace, en el origen de la propia app. */
export function publicUrl(token: string): string {
  return `${window.location.origin}/c/${token}`
}

export const useShareLinkStore = defineStore('shareLink', {
  state: (): ShareLinkState => ({ link: null, loaded: false, loading: false, error: false }),

  actions: {
    async fetch() {
      this.loading = true
      this.error = false
      try {
        const { data } = await apiClient.get('/share-link')
        this.link = data.data
        this.loaded = true
      } catch {
        this.error = true
      } finally {
        this.loading = false
      }
    },

    /** Crea el enlace o regenera el que hubiera (el anterior deja de valer). */
    async create(expiresInDays: number | null = DEFAULT_EXPIRY_DAYS) {
      const { data } = await apiClient.post('/share-link', { expires_in_days: expiresInDays })
      this.link = data.data
      this.loaded = true
    },

    async remove() {
      await apiClient.delete('/share-link')
      this.link = null
    },
  },
})
