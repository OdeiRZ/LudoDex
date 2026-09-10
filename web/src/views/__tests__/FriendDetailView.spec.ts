import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import FriendDetailView from '@/views/FriendDetailView.vue'
import GameDetailModal from '@/components/GameDetailModal.vue'
import { useFriendDetailStore } from '@/stores/friendDetail'
import type { Game } from '@/stores/games'
import { i18n } from '@/i18n'

function makeGame(overrides: Partial<Game> = {}): Game {
  return {
    id: 'g1',
    bgg_id: 13,
    base_game_id: null,
    base_game_name: null,
    name: 'Catan',
    image_url: null,
    description: null,
    description_es: null,
    year_published: null,
    min_age: null,
    bgg_rank: null,
    rating: null,
    min_players: null,
    max_players: null,
    min_playtime_minutes: null,
    max_playtime_minutes: null,
    weight: null,
    is_cooperative: false,
    is_competitive: true,
    has_campaign: false,
    mechanics: [],
    categories: [],
    ...overrides,
  }
}

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

  it('shows a not-shared message on both tabs, but keeps the page (name, tabs) when activityHidden is true', async () => {
    const { wrapper, store } = await mountDetail()
    store.friend = { id: 2, name: 'Friend One', bgg_username: null, avatar_url: null }
    store.activityHidden = true
    await flushPromises()

    expect(wrapper.find('.not-found').exists()).toBe(false)
    expect(wrapper.find('.tabs').exists()).toBe(true)
    expect(wrapper.text()).toContain('Este usuario no comparte su actividad contigo.')
    expect(wrapper.text()).toContain('Friend One')

    await wrapper.findAll('.tab')[1]!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Este usuario no comparte su actividad contigo.')
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

  it('opens the detail modal for the game whose eye button was clicked', async () => {
    const { wrapper, store } = await mountDetail()
    store.shared = [makeGame({ id: 'g1', name: 'Catan' })]
    store.mineOnly = [makeGame({ id: 'g2', name: 'Wingspan' })]
    await flushPromises()

    expect(wrapper.find('.modal-panel').exists()).toBe(false)

    const buttons = wrapper.findAll('.details-icon-button')
    await buttons[1]!.trigger('click')
    await flushPromises()

    expect(wrapper.find('.modal-panel h2').text()).toBe('Wingspan')
  })

  it("keeps a translated description in the list, not just the open modal, so reopening it doesn't revert to English", async () => {
    const { wrapper, store } = await mountDetail()
    const game = makeGame({
      id: 'g1',
      name: 'Catan',
      description: 'In English',
      description_es: null,
    })
    store.shared = [game]
    await flushPromises()

    await wrapper.find('.details-icon-button').trigger('click')
    await flushPromises()
    wrapper.findComponent(GameDetailModal).vm.$emit('translated', 'En español')
    await flushPromises()

    expect(game.description_es).toBe('En español')
  })

  it('shows the BGG rank badge for a ranked base game', async () => {
    const { wrapper, store } = await mountDetail()
    store.shared = [makeGame({ id: 'g1', bgg_id: 13, base_game_id: null, bgg_rank: 42 })]
    await flushPromises()

    expect(wrapper.find('.badge-rank').text()).toContain('42')
  })

  it('shows "unranked" for a base game without a BGG rank', async () => {
    const { wrapper, store } = await mountDetail()
    store.shared = [makeGame({ id: 'g1', bgg_id: 13, base_game_id: null, bgg_rank: null })]
    await flushPromises()

    expect(wrapper.find('.badge-rank').exists()).toBe(true)
    expect(wrapper.find('.badge-rank').text()).not.toContain('42')
  })

  it('never shows a rank badge for an expansion', async () => {
    const { wrapper, store } = await mountDetail()
    store.shared = [makeGame({ id: 'g1', bgg_id: 13, base_game_id: 'base-1', bgg_rank: 42 })]
    await flushPromises()

    expect(wrapper.find('.badge-rank').exists()).toBe(false)
  })

  it('shows an "Expansión de X" badge on an expansion instead of a rank badge', async () => {
    const { wrapper, store } = await mountDetail()
    store.shared = [
      makeGame({
        id: 'g1',
        bgg_id: 13,
        base_game_id: 'base-1',
        base_game_name: 'Catan',
        name: 'Catan: Seafarers',
      }),
    ]
    await flushPromises()

    expect(wrapper.find('.badge-expansion').text()).toBe('Expansión de Catan')
  })

  it('shows the A-Z scrubber once there are more than 12 games combined across all three sections', async () => {
    const { wrapper, store } = await mountDetail()
    store.shared = Array.from({ length: 5 }, (_, i) =>
      makeGame({ id: `s${i}`, name: `Shared ${i}` }),
    )
    store.mineOnly = Array.from({ length: 4 }, (_, i) =>
      makeGame({ id: `m${i}`, name: `Mine ${i}` }),
    )
    store.theirsOnly = Array.from({ length: 4 }, (_, i) =>
      makeGame({ id: `t${i}`, name: `Theirs ${i}` }),
    )
    await flushPromises()

    expect(wrapper.find('.az-scrubber').exists()).toBe(true)
  })

  it('does not show the A-Z scrubber with 12 or fewer games combined', async () => {
    const { wrapper, store } = await mountDetail()
    store.shared = Array.from({ length: 4 }, (_, i) =>
      makeGame({ id: `s${i}`, name: `Shared ${i}` }),
    )
    store.mineOnly = Array.from({ length: 4 }, (_, i) =>
      makeGame({ id: `m${i}`, name: `Mine ${i}` }),
    )
    store.theirsOnly = Array.from({ length: 4 }, (_, i) =>
      makeGame({ id: `t${i}`, name: `Theirs ${i}` }),
    )
    await flushPromises()

    expect(wrapper.find('.az-scrubber').exists()).toBe(false)
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

  it('shows a retry option on the collection tab instead of an endless spinner when the fetch fails', async () => {
    // Can't close over the outer `store` here - it runs as part of
    // mounting, before mountDetail() has returned and assigned `store`
    // below - see the identical note in FriendsView.spec.ts.
    const { wrapper, store } = await mountDetail(async () => {
      useFriendDetailStore().collectionError = true
    })

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('.loading-state').exists()).toBe(false)

    const retrySpy = vi.spyOn(store, 'fetchCollection').mockResolvedValue()
    await wrapper.find('.load-error button').trigger('click')

    expect(retrySpy).toHaveBeenCalledWith(2)
  })

  it('shows a retry option on the plays tab instead of an endless spinner when the fetch fails', async () => {
    const { wrapper, store } = await mountDetail()
    vi.spyOn(store, 'fetchPlays').mockImplementation(async () => {
      store.playsError = true
    })

    await wrapper.findAll('.tab')[1]!.trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('.loading-state').exists()).toBe(false)

    const retryPlaysSpy = vi.spyOn(store, 'fetchPlays').mockResolvedValue()
    const retryStatsSpy = vi.spyOn(store, 'fetchPlaysStats').mockResolvedValue()
    await wrapper.find('.load-error button').trigger('click')

    expect(retryPlaysSpy).toHaveBeenCalledWith(2, 1)
    expect(retryStatsSpy).toHaveBeenCalledWith(2)
  })
})
