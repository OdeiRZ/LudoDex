import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useGamesStore } from '@/stores/games'
import { usePlaysStore } from '@/stores/plays'
import { apiClient } from '@/lib/api'

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    apiClient: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
  }
})

const user = {
  id: 1,
  name: 'Odei',
  email: 'odei@example.com',
  bgg_username: null,
  avatar_url: null,
}

describe('useAuthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    vi.mocked(apiClient.get).mockReset()
    vi.mocked(apiClient.post).mockReset()
    vi.mocked(apiClient.put).mockReset()
  })

  it('starts unauthenticated when there is no stored token', () => {
    const store = useAuthStore()

    expect(store.isAuthenticated).toBe(false)
    expect(store.user).toBeNull()
  })

  it('logs in, storing the user and token', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({ data: { user, token: 'abc123' } })
    const store = useAuthStore()

    await store.login({ email: user.email, password: 'secret' })

    expect(store.user).toEqual(user)
    expect(store.token).toBe('abc123')
    expect(store.isAuthenticated).toBe(true)
    expect(localStorage.getItem('ludodex_token')).toBe('abc123')
    expect(apiClient.post).toHaveBeenCalledWith('/login', {
      email: user.email,
      password: 'secret',
      device_name: 'LudoDex Web',
    })
  })

  it('registers, storing the user and token the same way as login', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({ data: { user, token: 'def456' } })
    const store = useAuthStore()

    await store.register({
      name: user.name,
      email: user.email,
      password: 'secret',
      password_confirmation: 'secret',
    })

    expect(store.user).toEqual(user)
    expect(store.token).toBe('def456')
  })

  it('clears the session on logout even when the API call fails', async () => {
    vi.mocked(apiClient.post).mockResolvedValueOnce({ data: { user, token: 'abc123' } })
    const store = useAuthStore()
    await store.login({ email: user.email, password: 'secret' })

    vi.mocked(apiClient.post).mockRejectedValueOnce(new Error('network error'))

    await expect(store.logout()).rejects.toThrow('network error')

    expect(store.user).toBeNull()
    expect(store.token).toBeNull()
    expect(localStorage.getItem('ludodex_token')).toBeNull()
  })

  it("resets the games and plays stores on logout, so the next account never sees the previous one's data", async () => {
    vi.mocked(apiClient.post).mockResolvedValueOnce({ data: { user, token: 'abc123' } })
    const auth = useAuthStore()
    await auth.login({ email: user.email, password: 'secret' })

    // Simulates what a real session leaves behind: a loaded collection
    // and play history sitting in memory, not just the auth state.
    const games = useGamesStore()
    games.collection = [{ id: 'ug1' } as never]
    games.loaded = true
    const plays = usePlaysStore()
    plays.entries = [{ id: 'p1' } as never]
    plays.loaded = true

    vi.mocked(apiClient.post).mockResolvedValueOnce({})
    await auth.logout()

    expect(games.collection).toEqual([])
    expect(games.loaded).toBe(false)
    expect(plays.entries).toEqual([])
    expect(plays.loaded).toBe(false)
  })

  it('updates the profile from the API response', async () => {
    const store = useAuthStore()
    const updated = { ...user, name: 'Nuevo nombre' }
    vi.mocked(apiClient.put).mockResolvedValue({ data: { user: updated } })

    await store.updateProfile({ name: 'Nuevo nombre', email: user.email })

    expect(store.user).toEqual(updated)
  })

  it('returns the backend message from forgotPassword/resetPassword', async () => {
    const store = useAuthStore()
    vi.mocked(apiClient.post).mockResolvedValue({ data: { message: 'Enviado.' } })

    const message = await store.forgotPassword(user.email)

    expect(message).toBe('Enviado.')
  })

  describe('fetchCurrentUser', () => {
    it('does nothing when there is no token', async () => {
      const store = useAuthStore()

      await store.fetchCurrentUser()

      expect(apiClient.get).not.toHaveBeenCalled()
    })

    it('restores the user when the token is still valid', async () => {
      vi.mocked(apiClient.post).mockResolvedValueOnce({ data: { user, token: 'abc123' } })
      const store = useAuthStore()
      await store.login({ email: user.email, password: 'secret' })

      vi.mocked(apiClient.get).mockResolvedValueOnce({ data: user })
      await store.fetchCurrentUser()

      expect(store.user).toEqual(user)
    })

    it('clears the session when the stored token is rejected by the API', async () => {
      vi.mocked(apiClient.post).mockResolvedValueOnce({ data: { user, token: 'abc123' } })
      const store = useAuthStore()
      await store.login({ email: user.email, password: 'secret' })

      vi.mocked(apiClient.get).mockRejectedValueOnce(new Error('401'))
      await store.fetchCurrentUser()

      expect(store.user).toBeNull()
      expect(store.token).toBeNull()
    })
  })
})
