import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import PlaysView from '@/views/PlaysView.vue'
import { usePlaysStore, type Play } from '@/stores/plays'
import { useGamesStore } from '@/stores/games'
import { useToastStore } from '@/stores/toast'
import { i18n } from '@/i18n'

function makePlay(overrides: Partial<Play> = {}): Play {
  return {
    id: 'play-1',
    played_at: '2026-01-01',
    quantity: 1,
    duration_minutes: 30,
    game: {
      id: 'game-1',
      bgg_id: 13,
      name: 'Catan',
      image_url: null,
      description: 'Trade and build on the island of Catan.',
      description_es: null,
      base_game_name: null,
    },
    ...overrides,
  }
}

// A real router (rather than stubbing RouterLink out) so the empty
// state's link to Importar BGG's own Plays tab can be checked for real
// (resolved href), not just that a stub tag rendered.
function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'plays', component: PlaysView },
      { path: '/import-bgg', name: 'import-bgg', component: { template: '<div>Import</div>' } },
    ],
  })
}

async function mountPlays() {
  setActivePinia(createPinia())
  const store = usePlaysStore()
  vi.spyOn(store, 'fetchPage').mockResolvedValue()
  vi.spyOn(store, 'fetchStats').mockResolvedValue()

  const router = makeRouter()
  router.push('/')
  await router.isReady()

  const wrapper = mount(PlaysView, {
    global: { plugins: [router, i18n] },
  })

  return { wrapper, store }
}

describe('PlaysView', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('fetches page 1 on mount when nothing is loaded yet', async () => {
    const { store } = await mountPlays()

    expect(store.fetchPage).toHaveBeenCalledWith(1)
  })

  it('does not re-fetch on mount if plays are already loaded', async () => {
    setActivePinia(createPinia())
    const store = usePlaysStore()
    store.loaded = true
    vi.spyOn(store, 'fetchPage').mockResolvedValue()
    vi.spyOn(store, 'fetchStats').mockResolvedValue()

    const router = makeRouter()
    router.push('/')
    await router.isReady()
    mount(PlaysView, { global: { plugins: [router, i18n] } })

    expect(store.fetchPage).not.toHaveBeenCalled()
  })

  it('shows the loading state (dice spinner) before the first fetch resolves', async () => {
    const { wrapper, store } = await mountPlays()

    expect(store.loaded).toBe(false)
    expect(wrapper.find('.loading-state').exists()).toBe(true)
    expect(wrapper.findComponent({ name: 'LoadingSpinner' }).exists()).toBe(true)
    expect(wrapper.find('.play-list').exists()).toBe(false)
  })

  it('hides the loading state once loaded, even with no plays yet', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.loading-state').exists()).toBe(false)
  })

  it('does not show the loading state while "load more" fetches a later page', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    store.currentPage = 1
    store.lastPage = 2
    store.loading = true
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.loading-state').exists()).toBe(false)
    expect(wrapper.find('.play-list').exists()).toBe(true)
  })

  it('shows the empty state with a link to the Plays import tab once loaded with no plays', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.empty-state').exists()).toBe(true)
    expect(wrapper.find('.empty-state a').attributes('href')).toBe('/import-bgg?tab=plays')
    // Nothing to search yet - hidden for this specific empty state only.
    expect(wrapper.find('.search-field').exists()).toBe(false)
  })

  it('shows a different empty state for a search with no matches, keeping the search box', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.search = 'nonexistent'
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.search-field').exists()).toBe(true)
    expect(wrapper.find('.empty-state').text()).toBe('Ninguna partida coincide con la búsqueda.')
    expect(wrapper.find('.empty-state a').exists()).toBe(false)
  })

  it('debounces the search box, calling setSearch with the final value after 300ms of no typing', async () => {
    vi.useFakeTimers()
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    await wrapper.vm.$nextTick()
    const setSearchSpy = vi.spyOn(store, 'setSearch').mockResolvedValue()

    const input = wrapper.find('#plays-search')
    await input.setValue('cat')
    await input.setValue('cata')
    await input.setValue('catan')

    expect(setSearchSpy).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(300)

    expect(setSearchSpy).toHaveBeenCalledTimes(1)
    expect(setSearchSpy).toHaveBeenCalledWith('catan')
    vi.useRealTimers()
  })

  it('lists plays once loaded, including quantity and duration', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay({ quantity: 3, duration_minutes: 45 })]
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.empty-state').exists()).toBe(false)
    expect(wrapper.text()).toContain('Catan')
    expect(wrapper.text()).toContain('×3')
    expect(wrapper.text()).toContain('45 min')
  })

  it('numbers plays ascending, continuing across "load more" instead of resetting per page', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay({ id: 'play-1' }), makePlay({ id: 'play-2' })]
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('.play-index').map((el) => el.text())).toEqual(['1', '2'])

    // "Cargar más" appends to the same entries array rather than
    // replacing it - the numbering should pick up from 3, not restart.
    store.entries.push(makePlay({ id: 'play-3' }))
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('.play-index').map((el) => el.text())).toEqual(['1', '2', '3'])
  })

  it('shows the base game\'s name under a play logged against an expansion', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [
      makePlay({ id: 'play-1', game: { ...makePlay().game, name: 'Agora', base_game_name: '7 Wonders Duel' } }),
      makePlay({ id: 'play-2' }),
    ]
    await wrapper.vm.$nextTick()

    const baseGameLines = wrapper.findAll('.play-base-game')
    expect(baseGameLines).toHaveLength(1)
    expect(baseGameLines[0].text()).toBe('Expansión de 7 Wonders Duel')
  })

  it('shows a "load more" button only while there are more pages', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    store.currentPage = 1
    store.lastPage = 2
    await wrapper.vm.$nextTick()

    const loadMore = wrapper.find('.load-more')
    expect(loadMore.exists()).toBe(true)

    await loadMore.trigger('click')
    expect(store.fetchPage).toHaveBeenCalledWith(2)

    store.currentPage = 2
    store.lastPage = 2
    await wrapper.vm.$nextTick()
    expect(wrapper.find('.load-more').exists()).toBe(false)
  })

  it('opens the game detail modal when its cover is clicked, same as the eye icon elsewhere', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    await wrapper.vm.$nextTick()

    expect(wrapper.findComponent({ name: 'GameDetailModal' }).exists()).toBe(false)

    await wrapper.find('.play-cover-button').trigger('click')

    const modal = wrapper.findComponent({ name: 'GameDetailModal' })
    expect(modal.exists()).toBe(true)
    expect(modal.props('game').name).toBe('Catan')

    await modal.find('.modal-close').trigger('click')
    expect(wrapper.findComponent({ name: 'GameDetailModal' }).exists()).toBe(false)
  })

  it('keeps a translation after closing and reopening the same play\'s modal', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    await wrapper.vm.$nextTick()
    const games = useGamesStore()
    vi.spyOn(games, 'translateDescription').mockResolvedValue('Comercia y construye en la isla de Catan.')

    await wrapper.find('.play-cover-button').trigger('click')
    await wrapper.findComponent({ name: 'GameDetailModal' }).find('.modal-translate').trigger('click')
    await wrapper.vm.$nextTick()
    await wrapper.vm.$nextTick()
    await wrapper.findComponent({ name: 'GameDetailModal' }).find('.modal-close').trigger('click')

    // Reopening the same row's modal (a fresh instance, same underlying
    // play.game object) should show the translation already saved,
    // not fall back to English again waiting on a translate click.
    await wrapper.find('.play-cover-button').trigger('click')
    const reopened = wrapper.findComponent({ name: 'GameDetailModal' })
    expect(reopened.find('.modal-description').text()).toBe('Comercia y construye en la isla de Catan.')
    expect(reopened.find('.modal-translate').exists()).toBe(false)
  })

  it('toggles the reimport panel from its search-row button, closed by default', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.reimport-panel').exists()).toBe(false)

    await wrapper.find('.reimport-btn').trigger('click')
    expect(wrapper.find('.reimport-panel').exists()).toBe(true)

    await wrapper.find('.reimport-btn').trigger('click')
    expect(wrapper.find('.reimport-panel').exists()).toBe(false)
  })

  it('reimports plays for the typed username, refreshes the list and toasts the result', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    await wrapper.vm.$nextTick()
    const importSpy = vi
      .spyOn(store, 'importPlays')
      .mockResolvedValue({ imported_count: 2 })

    await wrapper.find('.reimport-btn').trigger('click')
    await wrapper.find('#reimport-username').setValue('odei1987')
    await wrapper.find('.reimport-panel .btn-primary').trigger('click')
    await wrapper.vm.$nextTick()
    await wrapper.vm.$nextTick()

    expect(importSpy).toHaveBeenCalledWith('odei1987', false)
    expect(store.fetchPage).toHaveBeenCalledWith(1)
    expect(wrapper.find('.reimport-panel').exists()).toBe(false)
    expect(useToastStore().message).toBe('2 partidas importadas.')
  })

  it('sends full: true when the full-reimport checkbox is checked', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    await wrapper.vm.$nextTick()
    const importSpy = vi
      .spyOn(store, 'importPlays')
      .mockResolvedValue({ imported_count: 2 })

    await wrapper.find('.reimport-btn').trigger('click')
    await wrapper.find('#reimport-username').setValue('odei1987')
    await wrapper.find('.reimport-full-label input[type="checkbox"]').setValue(true)
    await wrapper.find('.reimport-panel .btn-primary').trigger('click')
    await wrapper.vm.$nextTick()
    await wrapper.vm.$nextTick()

    expect(importSpy).toHaveBeenCalledWith('odei1987', true)
  })

  it('uses the singular form in the toast for exactly one reimported play', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    await wrapper.vm.$nextTick()
    vi.spyOn(store, 'importPlays').mockResolvedValue({ imported_count: 1 })

    await wrapper.find('.reimport-btn').trigger('click')
    await wrapper.find('#reimport-username').setValue('odei1987')
    await wrapper.find('.reimport-panel .btn-primary').trigger('click')
    await wrapper.vm.$nextTick()
    await wrapper.vm.$nextTick()

    expect(useToastStore().message).toBe('1 partida importada.')
  })

  it('shows an error inside the panel (without closing it) if reimporting fails', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    await wrapper.vm.$nextTick()
    vi.spyOn(store, 'importPlays').mockRejectedValue(new Error('network error'))

    await wrapper.find('.reimport-btn').trigger('click')
    await wrapper.find('#reimport-username').setValue('odei1987')
    await wrapper.find('.reimport-panel .btn-primary').trigger('click')
    await wrapper.vm.$nextTick()
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.reimport-panel').exists()).toBe(true)
    expect(wrapper.find('.reimport-panel .alert-error').text()).toBe(
      'No se han podido importar las partidas.',
    )
  })

  it('shows the loading spinner while a reimport is in flight, hides it once it settles', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    await wrapper.vm.$nextTick()

    let resolveImport!: (value: { imported_count: number }) => void
    vi.spyOn(store, 'importPlays').mockReturnValue(
      new Promise((resolve) => {
        resolveImport = resolve
      }),
    )

    await wrapper.find('.reimport-btn').trigger('click')
    expect(
      wrapper.findAll('.reimport-row .btn').filter((b) => !b.classes('btn-primary')),
    ).toHaveLength(1)

    await wrapper.find('#reimport-username').setValue('odei1987')
    await wrapper.find('.reimport-panel .btn-primary').trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.reimport-submitting').exists()).toBe(true)
    expect(wrapper.findComponent({ name: 'LoadingSpinner' }).exists()).toBe(true)
    // Cancelar is removed rather than just disabled once the growing
    // "Importando..." label starts squeezing the row (reported directly) -
    // nothing left to back out of at this point anyway.
    expect(
      wrapper.findAll('.reimport-row .btn').filter((b) => !b.classes('btn-primary')),
    ).toHaveLength(0)

    resolveImport({ imported_count: 1 })
    await flushPromises()

    expect(wrapper.find('.reimport-submitting').exists()).toBe(false)
  })

  it('disables the confirm button until a username is typed', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    await wrapper.vm.$nextTick()

    await wrapper.find('.reimport-btn').trigger('click')
    const confirmBtn = wrapper.find('.reimport-panel .btn-primary')
    expect((confirmBtn.element as HTMLButtonElement).disabled).toBe(true)

    await wrapper.find('#reimport-username').setValue('odei1987')
    expect((confirmBtn.element as HTMLButtonElement).disabled).toBe(false)
  })

  it('fetches stats on mount when not already loaded, skips it if already present', async () => {
    const { store } = await mountPlays()
    expect(store.fetchStats).toHaveBeenCalledTimes(1)

    setActivePinia(createPinia())
    const store2 = usePlaysStore()
    store2.stats = {
      total_plays: 1,
      distinct_games: 1,
      total_minutes: 30,
      duration_known_plays: 1,
      top_played: [],
    }
    vi.spyOn(store2, 'fetchPage').mockResolvedValue()
    vi.spyOn(store2, 'fetchStats').mockResolvedValue()
    const router = makeRouter()
    router.push('/')
    await router.isReady()
    mount(PlaysView, { global: { plugins: [router, i18n] } })

    expect(store2.fetchStats).not.toHaveBeenCalled()
  })

  it('hides the stats bar until there is at least one play', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.stats-bar').exists()).toBe(false)

    store.stats = {
      total_plays: 3,
      distinct_games: 2,
      total_minutes: 90,
      duration_known_plays: 3,
      top_played: [],
    }
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.stats-bar').exists()).toBe(true)
  })

  it('shows total plays, distinct games and an hours+minutes total time', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.stats = {
      total_plays: 10,
      distinct_games: 4,
      total_minutes: 135,
      duration_known_plays: 8,
      top_played: [],
    }
    await wrapper.vm.$nextTick()

    const tiles = wrapper.findAll('.stat-value').map((el) => el.text())
    expect(tiles).toContain('10')
    expect(tiles).toContain('4')
    expect(tiles).toContain('2 h 15 min')
  })

  it('shows a bare hours total when the total is an exact number of hours', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.stats = {
      total_plays: 4,
      distinct_games: 1,
      total_minutes: 120,
      duration_known_plays: 4,
      top_played: [],
    }
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('.stat-value').map((el) => el.text())).toContain('2 h')
  })

  it('shows "Sin datos" for total time when no play has a known duration', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.stats = {
      total_plays: 2,
      distinct_games: 1,
      total_minutes: 0,
      duration_known_plays: 0,
      top_played: [],
    }
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('.stat-value').map((el) => el.text())).toContain('Sin datos')
  })

  it('lists the top played games ranked, with a pluralized play count each', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.stats = {
      total_plays: 8,
      distinct_games: 3,
      total_minutes: 240,
      duration_known_plays: 8,
      top_played: [
        { game: { id: 'game-1', name: 'Catan', image_url: null }, count: 5, breakdown: null },
        { game: { id: 'game-2', name: '7 Wonders', image_url: null }, count: 1, breakdown: null },
      ],
    }
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.top-played-title').text()).toBe('Más jugados')

    const rows = wrapper.findAll('.top-played-row')
    expect(rows).toHaveLength(2)
    expect(rows[0].find('.top-played-rank').text()).toBe('1')
    expect(rows[0].find('.top-played-name').text()).toBe('Catan')
    expect(rows[0].find('.top-played-count').text()).toBe('5 veces')
    expect(rows[1].find('.top-played-rank').text()).toBe('2')
    expect(rows[1].find('.top-played-name').text()).toBe('7 Wonders')
    expect(rows[1].find('.top-played-count').text()).toBe('1 vez')
    expect(wrapper.find('.top-played-breakdown').exists()).toBe(false)
  })

  it('shows a breakdown by specific game under a rolled-up top-played entry', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.stats = {
      total_plays: 5,
      distinct_games: 1,
      total_minutes: 0,
      duration_known_plays: 0,
      top_played: [
        {
          game: { id: 'base-1', name: '7 Wonders Duel', image_url: null },
          count: 5,
          breakdown: [
            { game: { id: 'expansion-1', name: '7 Wonders Duel: Agora', image_url: null }, count: 3 },
            { game: { id: 'base-1', name: '7 Wonders Duel', image_url: null }, count: 2 },
          ],
        },
      ],
    }
    await wrapper.vm.$nextTick()

    const breakdownRows = wrapper.findAll('.top-played-breakdown-row')
    expect(breakdownRows).toHaveLength(2)
    expect(breakdownRows[0].find('.top-played-breakdown-name').text()).toBe('7 Wonders Duel: Agora')
    expect(breakdownRows[0].find('.top-played-breakdown-count').text()).toBe('3 veces')
    expect(breakdownRows[1].find('.top-played-breakdown-name').text()).toBe('7 Wonders Duel')
    expect(breakdownRows[1].find('.top-played-breakdown-count').text()).toBe('2 veces')
  })

  it('hides the top played list when there is nothing to rank', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.stats = {
      total_plays: 0,
      distinct_games: 0,
      total_minutes: 0,
      duration_known_plays: 0,
      top_played: [],
    }
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.top-played').exists()).toBe(false)
  })

  it('also refreshes stats after a successful reimport', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    store.entries = [makePlay()]
    await wrapper.vm.$nextTick()
    vi.spyOn(store, 'importPlays').mockResolvedValue({ imported_count: 1 })
    const fetchStatsSpy = vi.spyOn(store, 'fetchStats').mockResolvedValue()

    await wrapper.find('.reimport-btn').trigger('click')
    await wrapper.find('#reimport-username').setValue('odei1987')
    await wrapper.find('.reimport-panel .btn-primary').trigger('click')
    await flushPromises()

    expect(fetchStatsSpy).toHaveBeenCalled()
  })
})
