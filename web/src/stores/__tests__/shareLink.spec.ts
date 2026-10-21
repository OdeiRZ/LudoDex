import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { apiClient } from '@/lib/api'
import {
  DEFAULT_EXPIRY_DAYS,
  EXPIRY_OPTIONS,
  publicUrl,
  useShareLinkStore,
} from '@/stores/shareLink'

vi.mock('@/lib/api', () => ({
  apiClient: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

const link = {
  token: 'a'.repeat(43),
  expires_at: '2026-10-28T10:00:00+00:00',
  expired: false,
  views_count: 0,
  last_viewed_at: null,
}

describe('useShareLinkStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.resetAllMocks()
  })

  it('defaults to 7 days and offers 7, 30, 90 days or never', () => {
    expect(DEFAULT_EXPIRY_DAYS).toBe(7)
    expect(EXPIRY_OPTIONS).toEqual([7, 30, 90, null])
  })

  it("builds the public address on the app's own origin", () => {
    expect(publicUrl('abc')).toBe(`${window.location.origin}/c/abc`)
  })

  it('loads the current link, or none', async () => {
    vi.mocked(apiClient.get).mockResolvedValueOnce({ data: { data: null } })
    const store = useShareLinkStore()

    await store.fetch()

    expect(store.link).toBeNull()
    expect(store.loaded).toBe(true)
    expect(store.error).toBe(false)
  })

  it('flags an error without throwing when the request fails', async () => {
    vi.mocked(apiClient.get).mockRejectedValueOnce(new Error('network'))
    const store = useShareLinkStore()

    await store.fetch()

    expect(store.error).toBe(true)
    expect(store.loaded).toBe(false)
  })

  it('creates with the chosen expiry and keeps the returned link', async () => {
    vi.mocked(apiClient.post).mockResolvedValueOnce({ data: { data: link } })
    const store = useShareLinkStore()

    await store.create(30)

    expect(apiClient.post).toHaveBeenCalledWith('/share-link', { expires_in_days: 30 })
    expect(store.link).toEqual(link)
  })

  it('creates with 7 days when no expiry is given, and with null for "never"', async () => {
    vi.mocked(apiClient.post).mockResolvedValue({ data: { data: link } })
    const store = useShareLinkStore()

    await store.create()
    await store.create(null)

    expect(apiClient.post).toHaveBeenNthCalledWith(1, '/share-link', { expires_in_days: 7 })
    expect(apiClient.post).toHaveBeenNthCalledWith(2, '/share-link', { expires_in_days: null })
  })

  it('removes the link', async () => {
    vi.mocked(apiClient.delete).mockResolvedValueOnce({})
    const store = useShareLinkStore()
    store.link = link

    await store.remove()

    expect(apiClient.delete).toHaveBeenCalledWith('/share-link')
    expect(store.link).toBeNull()
  })
})
