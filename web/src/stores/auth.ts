import { defineStore } from 'pinia'
import { apiClient, clearStoredToken, getStoredToken, storeToken } from '@/lib/api'

export interface User {
  id: number
  name: string
  email: string
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

    clearSession() {
      this.user = null
      this.token = null
      clearStoredToken()
    },
  },
})
