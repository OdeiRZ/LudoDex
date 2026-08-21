import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import PlaysView from '@/views/PlaysView.vue'
import { usePlaysStore, type Play } from '@/stores/plays'
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

    const router = makeRouter()
    router.push('/')
    await router.isReady()
    mount(PlaysView, { global: { plugins: [router, i18n] } })

    expect(store.fetchPage).not.toHaveBeenCalled()
  })

  it('shows the empty state with a link to the Plays import tab once loaded with no plays', async () => {
    const { wrapper, store } = await mountPlays()
    store.loaded = true
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.empty-state').exists()).toBe(true)
    expect(wrapper.find('.empty-state a').attributes('href')).toBe('/import-bgg?tab=plays')
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
})
