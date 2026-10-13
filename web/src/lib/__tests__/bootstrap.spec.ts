import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

vi.mock('@/lib/api', () => ({
  apiClient: { get: vi.fn() },
  getStoredToken: vi.fn(),
}))

const TOKEN = '1|abcdefghijklmnopqrstuvwxyz'

function payload(name = 'Catan') {
  return {
    user: { id: 1, name: 'Odei' },
    games: [{ id: 'g1', status: 'owned', game: { id: 'g1', name } }],
    mechanics: [{ id: 1, name: 'Trading' }],
    categories: [{ id: 1, name: 'Negotiation' }],
    friends: [],
    requests: { incoming: [], outgoing: [] },
    blocks: [],
  }
}

// El módulo guarda la petición en curso a nivel de módulo: cada test lo carga limpio.
async function load() {
  vi.resetModules()
  const api = await import('@/lib/api')
  const boot = await import('@/lib/bootstrap')
  vi.mocked(api.getStoredToken).mockReturnValue(TOKEN)
  return { get: vi.mocked(api.apiClient.get), getStoredToken: vi.mocked(api.getStoredToken), ...boot }
}

describe('lib/bootstrap', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('does nothing without a session token', async () => {
    const { get, getStoredToken, prefetchBootstrap, takeBootstrap } = await load()
    getStoredToken.mockReturnValue(null)

    prefetchBootstrap()

    expect(get).not.toHaveBeenCalled()
    expect(await takeBootstrap('games')).toBeNull()
  })

  it('asks the API once, however many times it is started', async () => {
    const { get, prefetchBootstrap } = await load()
    get.mockResolvedValue({ status: 200, data: payload(), headers: { etag: '"v1"' } })

    prefetchBootstrap()
    prefetchBootstrap()

    expect(get).toHaveBeenCalledTimes(1)
    expect(get).toHaveBeenCalledWith('/bootstrap', expect.objectContaining({ headers: undefined }))
  })

  it('hands each part (user, friends, games) out once, then goes back to normal requests', async () => {
    const { get, prefetchBootstrap, takeBootstrap } = await load()
    get.mockResolvedValue({ status: 200, data: payload(), headers: { etag: '"v1"' } })
    prefetchBootstrap()

    expect((await takeBootstrap('user'))?.games[0]?.game.name).toBe('Catan')
    expect(await takeBootstrap('user')).toBeNull() // esa parte ya se recogió
    expect(await takeBootstrap('friends')).not.toBeNull()
    expect(await takeBootstrap('games')).not.toBeNull()
    expect(await takeBootstrap('games')).toBeNull() // ya no queda nada: lo siguiente va por la red
  })

  it('keeps the response and its ETag to send If-None-Match next time, and uses what it kept on a 304', async () => {
    const first = await load()
    first.get.mockResolvedValue({ status: 200, data: payload('Catan'), headers: { etag: '"v1"' } })
    first.prefetchBootstrap()
    await first.takeBootstrap('games')

    const second = await load() // otra visita: el módulo empieza de cero, localStorage no
    second.get.mockResolvedValue({ status: 304, data: '', headers: { etag: '"v1"' } })
    second.prefetchBootstrap()

    expect(second.get).toHaveBeenCalledWith(
      '/bootstrap',
      expect.objectContaining({ headers: { 'If-None-Match': '"v1"' } }),
    )
    expect((await second.takeBootstrap('games'))?.games[0]?.game.name).toBe('Catan')
  })

  it('accepts a 304 from axios instead of treating it as an error', async () => {
    const { get, prefetchBootstrap } = await load()
    get.mockResolvedValue({ status: 200, data: payload(), headers: {} })

    prefetchBootstrap()

    const options = get.mock.calls[0]?.[1] as { validateStatus: (status: number) => boolean }
    expect(options.validateStatus(200)).toBe(true)
    expect(options.validateStatus(304)).toBe(true)
    expect(options.validateStatus(401)).toBe(false)
    expect(options.validateStatus(500)).toBe(false)
  })

  it('falls back to normal requests when the API answers 304 but nothing was kept', async () => {
    const { get, prefetchBootstrap, takeBootstrap } = await load()
    get.mockResolvedValue({ status: 304, data: '', headers: {} })

    prefetchBootstrap()

    expect(await takeBootstrap('games')).toBeNull()
  })

  it('ignores a copy kept by another session (different token)', async () => {
    const first = await load()
    first.get.mockResolvedValue({ status: 200, data: payload('De otro'), headers: { etag: '"v1"' } })
    first.prefetchBootstrap()
    await first.takeBootstrap('games')

    const second = await load()
    second.getStoredToken.mockReturnValue('9|zzzzzzzzzzzzzzzzzzzzzzzzzz')
    second.get.mockResolvedValue({ status: 200, data: payload('Mío'), headers: { etag: '"v2"' } })
    second.prefetchBootstrap()

    expect(second.get).toHaveBeenCalledWith('/bootstrap', expect.objectContaining({ headers: undefined }))
    expect((await second.takeBootstrap('games'))?.games[0]?.game.name).toBe('Mío')
  })

  it('ignores a damaged or old copy instead of failing', async () => {
    localStorage.setItem('ludodex.bootstrap', '{esto no es json')
    const { get, prefetchBootstrap, takeBootstrap } = await load()
    get.mockResolvedValue({ status: 200, data: payload(), headers: { etag: '"v1"' } })

    prefetchBootstrap()

    expect(get).toHaveBeenCalledWith('/bootstrap', expect.objectContaining({ headers: undefined }))
    expect(await takeBootstrap('games')).not.toBeNull()
  })

  it('never throws when the request fails: each store just asks for its own data', async () => {
    const { get, prefetchBootstrap, takeBootstrap } = await load()
    get.mockRejectedValue(new Error('network error'))

    prefetchBootstrap()

    await expect(takeBootstrap('user')).resolves.toBeNull()
    await expect(takeBootstrap('games')).resolves.toBeNull()
  })

  it('stops handing the response out after 30 seconds, so a later refresh asks for fresh data', async () => {
    vi.useFakeTimers()
    const { get, prefetchBootstrap, takeBootstrap, BOOTSTRAP_FRESH_MS } = await load()
    get.mockResolvedValue({ status: 200, data: payload(), headers: { etag: '"v1"' } })
    prefetchBootstrap()
    await vi.advanceTimersByTimeAsync(10)

    vi.setSystemTime(Date.now() + BOOTSTRAP_FRESH_MS + 1000)

    expect(await takeBootstrap('games')).toBeNull()
  })

  it('forgets the kept copy and the pending request on logout', async () => {
    const { get, prefetchBootstrap, takeBootstrap, resetBootstrap } = await load()
    get.mockResolvedValue({ status: 200, data: payload(), headers: { etag: '"v1"' } })
    prefetchBootstrap()
    await takeBootstrap('user')
    expect(localStorage.getItem('ludodex.bootstrap')).not.toBeNull()

    resetBootstrap()

    expect(localStorage.getItem('ludodex.bootstrap')).toBeNull()
    expect(await takeBootstrap('games')).toBeNull()
  })
})
