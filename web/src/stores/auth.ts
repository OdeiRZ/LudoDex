import { defineStore } from 'pinia'
import { apiClient, clearStoredToken, getStoredToken, storeToken } from '@/lib/api'
import { useGamesStore } from './games'
import { usePlaysStore } from './plays'

export interface User {
  id: number
  name: string
  email: string
  bgg_username: string | null
  avatar_url: string | null
}

interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
}

interface LoginPayload {
  email: string
  password: string
}

interface UpdateProfilePayload {
  name: string
  email: string
  bgg_username?: string | null
}

interface UpdatePasswordPayload {
  current_password: string
  password: string
  password_confirmation: string
}

interface ResetPasswordPayload {
  token: string
  email: string
  password: string
  password_confirmation: string
}

interface AuthState {
  user: User | null
  token: string | null
}

// Labels the token so a future "sesiones activas" screen could tell devices
// apart; not tied to any real device detection for now.
const DEVICE_NAME = 'LudoDex Web'

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    user: null,
    token: getStoredToken(),
  }),

  getters: {
    isAuthenticated: (state) => state.token !== null,
  },

  actions: {
    async register(payload: RegisterPayload) {
      const { data } = await apiClient.post('/register', {
        ...payload,
        device_name: DEVICE_NAME,
      })

      this.setSession(data.user, data.token)
    },

    async login(payload: LoginPayload) {
      const { data } = await apiClient.post('/login', {
        ...payload,
        device_name: DEVICE_NAME,
      })

      this.setSession(data.user, data.token)
    },

    async logout() {
      try {
        await apiClient.post('/logout')
      } finally {
        this.clearSession()
      }
    },

    async updateProfile(payload: UpdateProfilePayload) {
      const { data } = await apiClient.put('/user', payload)
      this.user = data.user
    },

    async updatePassword(payload: UpdatePasswordPayload) {
      await apiClient.put('/user/password', payload)
    },

    /** Returns the backend's own status message (already in the right
     * language) rather than a fixed string, so the view can show it as is. */
    async forgotPassword(email: string): Promise<string> {
      const { data } = await apiClient.post('/forgot-password', { email })
      return data.message
    },

    async resetPassword(payload: ResetPasswordPayload): Promise<string> {
      const { data } = await apiClient.post('/reset-password', payload)
      return data.message
    },

    /** Restores `user` from a token already in storage (e.g. after a page reload). */
    async fetchCurrentUser() {
      if (!this.token) {
        return
      }

      try {
        const { data } = await apiClient.get('/user')
        this.user = data
      } catch {
        this.clearSession()
      }
    },

    setSession(user: User, token: string) {
      this.user = user
      this.token = token
      storeToken(token)
    },

    /** Logging out (or a 401 auto-logout, see api.ts's own interceptor)
     * only ever clears *this* store's own state - without also resetting
     * games/plays here, whoever's collection/history was already loaded
     * in memory stayed there. A next user registering or logging in on
     * the same tab (never possible without going through this first -
     * see the router's guestOnly guard) would briefly see the previous
     * account's data until something forced a refetch, since both
     * stores' own onMounted guards only fetch when not already
     * `loaded`. $reset() (built into Pinia's Options API stores) puts
     * each back to its own initial state() exactly as if the tab had
     * just been opened. */
    clearSession() {
      this.user = null
      this.token = null
      clearStoredToken()
      useGamesStore().$reset()
      usePlaysStore().$reset()
    },
  },
})
