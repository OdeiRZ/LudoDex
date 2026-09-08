import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import FriendDetailView from '@/views/FriendDetailView.vue'
import { useFriendDetailStore } from '@/stores/friendDetail'
import { i18n } from '@/i18n'

function makeRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/friends', name: 'friends', component: { template: '<div>Friends</div>' } },
      { path: '/friends/:friendId', name: 'friend-detail', component: FriendDetailView },
    ],
  })
  router.push('/friends/2')
  return router
}

async function mountDetail(fetchCollectionImpl?: () => Promise<void>) {
  setActivePinia(createPinia())
  const store = useFriendDetailStore()
  vi.spyOn(store, 'fetchCollection').mockImplementation(
    fetchCollectionImpl ??
      (async () => {
        store.collectionLoaded = true
      }),
  )
  vi.spyOn(store, 'fetchPlays').mockImplementation(async () => {
    store.playsLoaded = true
  })
  vi.spyOn(store, 'fetchPlaysStats').mockResolvedValue()

  const router = makeRouter()
  await router.isReady()
  const wrapper = mount(FriendDetailView, {
    props: { friendId: 2 },
    global: { plugins: [router, i18n] },
  })
  await flushPromises()

  return { wrapper, store }
}

describe('FriendDetailView', () => {
  it('shows a loading state until the collection finishes loading', async () => {
    const { wrapper } = await mountDetail(() => new Promise(() => {}))

    expect(wrapper.find('.loading-state').exists()).toBe(true)
  })

  it('shows the not-found state without any other content when notFound is true', async () => {
    const { wrapper, store } = await mountDetail()
    store.notFound = true
    await flushPromises()

    expect(wrapper.find('.not-found').exists()).toBe(true)
    expect(wrapper.find('.tabs').exists()).toBe(false)
  })

  it('lists games in the shared, mineOnly and theirsOnly sections', async () => {
    const { wrapper, store } = await mountDetail()
    store.friend = { id: 2, name: 'Friend One', bgg_username: null, avatar_url: null }
    store.shared = [{ id: 'g1', name: 'Catan' } as never]
    store.mineOnly = [{ id: 'g2', name: 'Wingspan' } as never]
    store.theirsOnly = [{ id: 'g3', name: 'Azul' } as never]
    await flushPromises()

    expect(wrapper.text()).toContain('Catan')
    expect(wrapper.text()).toContain('Wingspan')
    expect(wrapper.text()).toContain('Azul')
  })

  it('never renders edit/remove action buttons on any game card', async () => {
    const { wrapper, store } = await mountDetail()
    store.shared = [{ id: 'g1', name: 'Catan' } as never]
    await flushPromises()

    expect(wrapper.find('.card-actions').exists()).toBe(false)
  })

  it('loads plays only the first time the plays tab is opened', async () => {
    const { wrapper, store } = await mountDetail()

    const tabs = wrapper.findAll('.tab')
    await tabs[1]!.trigger('click')
    await flushPromises()
    await tabs[0]!.trigger('click')
    await flushPromises()
    await tabs[1]!.trigger('click')
    await flushPromises()

    expect(store.fetchPlays).toHaveBeenCalledTimes(1)
  })

  it("shows the friend's plays once loaded on the plays tab", async () => {
    const { wrapper, store } = await mountDetail()
    const tabs = wrapper.findAll('.tab')
    await tabs[1]!.trigger('click')
    await flushPromises()

    store.plays = [
      {
        id: 'p1',
        played_at: '2026-01-01',
        quantity: 1,
        duration_minutes: 30,
        game: {
          id: 'g1',
          bgg_id: 13,
          name: 'Catan',
          image_url: null,
          description: null,
          description_es: null,
          base_game_name: null,
        },
      },
    ]
    await flushPromises()

    expect(wrapper.text()).toContain('Catan')
  })
})
