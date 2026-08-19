import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PlaysView from '@/views/PlaysView.vue'
import { usePlaysStore, type Play } from '@/stores/plays'
import { i18n } from '@/i18n'

function makePlay(overrides: Partial<Play> = {}): Play {
  return {
    id: 'play-1',
    played_at: '2026-01-01',
    quantity: 1,
    duration_minutes: 30,
    game: { id: 'game-1', bgg_id: 13, name: 'Catan', image_url: null },
    ...overrides,
  }
}

function mountPlays() {
  setActivePinia(createPinia())
  const store = usePlaysStore()
  vi.spyOn(store, 'fetchPage').mockResolvedValue()

  const wrapper = mount(PlaysView, {
    global: { stubs: { RouterLink: true }, plugins: [i18n] },
  })

  return { wrapper, store }
}

async function submitUsername(wrapper: ReturnType<typeof mountPlays>['wrapper'], username = 'odei') {
  await wrapper.find('#plays_bgg_username').setValue(username)
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

describe('PlaysView', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('fetches page 1 on mount when nothing is loaded yet', () => {
    const { store } = mountPlays()

    expect(store.fetchPage).toHaveBeenCalledWith(1)
  })

  it('does not re-fetch on mount if plays are already loaded', () => {
    setActivePinia(createPinia())
    const store = usePlaysStore()
    store.loaded = true
    vi.spyOn(store, 'fetchPage').mockResolvedValue()

    mount(PlaysView, { global: { stubs: { RouterLink: true }, plugins: [i18n] } })

    expect(store.fetchPage).not.toHaveBeenCalled()
  })

  it('shows the empty state once loaded with no plays', async () => {
    const { wrapper, store } = mountPlays()
    store.loaded = true
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.empty-state').exists()).toBe(true)
  })

  it('lists plays once loaded, including quantity and duration', async () => {
    const { wrapper, store } = mountPlays()
    store.loaded = true
    store.entries = [makePlay({ quantity: 3, duration_minutes: 45 })]
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.empty-state').exists()).toBe(false)
    expect(wrapper.text()).toContain('Catan')
    expect(wrapper.text()).toContain('×3')
    expect(wrapper.text()).toContain('45 min')
  })

  it('shows a "load more" button only while there are more pages', async () => {
    const { wrapper, store } = mountPlays()
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

  it('imports plays and shows the success count', async () => {
    const { wrapper, store } = mountPlays()
    vi.spyOn(store, 'importPlays').mockResolvedValue({ imported_count: 7 })

    await submitUsername(wrapper)

    expect(store.importPlays).toHaveBeenCalledWith('odei')
    expect(store.fetchPage).toHaveBeenCalledWith(1)
    expect(wrapper.text()).toContain('7 partidas importadas.')
  })

  it('shows an error message when the import fails', async () => {
    const { wrapper, store } = mountPlays()
    vi.spyOn(store, 'importPlays').mockRejectedValue(new Error('network error'))

    await submitUsername(wrapper)

    expect(wrapper.find('[role="alert"]').text()).toContain('No se han podido importar las partidas.')
  })
})
