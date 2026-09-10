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

  it('sorts each section alphabetically, regardless of the order the API returned it in', async () => {
    // Regression test for a real bug found live: the API response isn't
    // guaranteed alphabetical, and the three sections were rendered in
    // that raw order - the A-Z scrubber assumes the list it jumps
    // around IS alphabetically sorted (same premise a phone book's own
    // index relies on), so scrubbing to a letter on an unsorted list
    // could land near a same-letter card that wasn't actually the
    // alphabetically-first one, or right next to an unrelated one.
    // DashboardView already sorts its own collection the same way
    // (a.game.name.localeCompare(b.game.name)) - this brings
    // FriendDetailView in line with it.
    const { wrapper, store } = await mountDetail()
    store.shared = [
      { id: 'g1', name: 'Wingspan' } as never,
      { id: 'g2', name: 'Azul' } as never,
      { id: 'g3', name: 'Catan' } as never,
    ]
    await flushPromises()

    const names = wrapper.findAll('.collection-section h3').map((el) => el.text())
    expect(names).toEqual(['Azul', 'Catan', 'Wingspan'])
  })

  it('collapses and re-expands a collection section by clicking its header', async () => {
    const { wrapper, store } = await mountDetail()
    store.shared = [{ id: 'g1', name: 'Catan' } as never]
    await flushPromises()

    const header = wrapper.find('.collection-section-header')
    const list = wrapper.find('.collection-section .games')

    // .isVisible() doesn't reliably reflect v-show's inline display: none
    // in this jsdom test environment (confirmed via the actual rendered
    // HTML) - the style attribute itself is what's checked instead.
    expect(header.attributes('aria-expanded')).toBe('true')
    expect(list.attributes('style') ?? '').not.toContain('display: none')

    await header.trigger('click')

    expect(header.attributes('aria-expanded')).toBe('false')
    expect(list.attributes('style')).toContain('display: none')

    await header.trigger('click')

    expect(header.attributes('aria-expanded')).toBe('true')
    expect(list.attributes('style') ?? '').not.toContain('display: none')
  })

  it('collapses each collection section independently', async () => {
    const { wrapper, store } = await mountDetail()
    store.shared = [{ id: 'g1', name: 'Catan' } as never]
    store.mineOnly = [{ id: 'g2', name: 'Wingspan' } as never]
    await flushPromises()

    const headers = wrapper.findAll('.collection-section-header')
    await headers[0]!.trigger('click')

    const lists = wrapper.findAll('.collection-section .games')
    expect(lists[0]!.attributes('style')).toContain('display: none')
    expect(lists[1]!.attributes('style') ?? '').not.toContain('display: none')
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

  it('excludes a collapsed section from both the scrubber threshold and its available letters', async () => {
    const { wrapper, store } = await mountDetail()
    store.shared = Array.from({ length: 13 }, (_, i) =>
      makeGame({ id: `s${i}`, name: `Apple ${i}` }),
    )
    await flushPromises()
    expect(wrapper.find('.az-scrubber').exists()).toBe(true)

    await wrapper.find('.collection-section-header').trigger('click')

    // Collapsing the only section with enough games to cross the
    // threshold drops the scrubber entirely - its letters were never
    // "available" to a section with nothing currently visible to jump
    // to (asked for directly, after an earlier version could still jump
    // into - or worse, transiently hide behind - a collapsed section).
    expect(wrapper.find('.az-scrubber').exists()).toBe(false)
  })

  it("does not offer a collapsed section's own letters even while another section is expanded", async () => {
    const { wrapper, store } = await mountDetail()
    store.shared = Array.from({ length: 13 }, (_, i) =>
      makeGame({ id: `s${i}`, name: `Apple ${i}` }),
    )
    store.mineOnly = [makeGame({ id: 'm1', name: 'Zeppelin' })]
    await flushPromises()
    expect(wrapper.find('.az-scrubber').exists()).toBe(true)

    // Collapses mineOnly (second header in the DOM) - Zeppelin's own "Z"
    // stops being reachable, but shared's own letters are unaffected.
    const headers = wrapper.findAll('.collection-section-header')
    await headers[1]!.trigger('click')

    const letters = wrapper.findAll('.az-scrubber-letter-available').map((el) => el.text())
    expect(letters).toContain('A')
    expect(letters).not.toContain('Z')
  })

  it('scrubbing to a letter jumps to the expanded section\'s own card, not a same-letter card hidden in a collapsed one', async () => {
    // Regression test for a real bug found live: a collapsed section's
    // own cards stay in the DOM (v-show, not v-if - see scrubberPool's
    // own comment on why), and a display: none element's
    // getBoundingClientRect() reports every value including top as 0 -
    // indistinguishable from "sitting exactly at the viewport's own top
    // edge" in resolveJumpTarget's own "nearest" comparison unless
    // filtered out first. Without that filter, scrubbing to a letter
    // that both a collapsed and an expanded section share silently
    // jumped nowhere useful instead of the one visible match.
    //
    // jsdom has no real layout - scrollIntoView and setPointerCapture
    // aren't implemented at all, and getBoundingClientRect always
    // reports zeroes - so this stubs all three deliberately: a fixed,
    // evenly-spaced rect for the scrubber strip itself (so bucketAtPointer
    // resolves a real bucket instead of dividing by zero), 0 for every
    // element inside the collapsed 'shared' section (simulating v-show:
    // false), and a distinct non-zero value for theirsOnly's own match.
    const scrollIntoView = vi.fn()
    Element.prototype.scrollIntoView = scrollIntoView
    HTMLElement.prototype.setPointerCapture = vi.fn()
    const rectSpy = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (
      this: HTMLElement,
    ) {
      const base = { bottom: 0, left: 0, right: 0, width: 0, x: 0, y: 0, toJSON: () => ({}) }
      if (this.classList.contains('az-scrubber-buckets')) {
        return { ...base, top: 0, height: 270 } as DOMRect
      }
      if (this.getAttribute('data-letter') === 'A') {
        const collapsed = this.closest('.collection-section')?.getAttribute('data-section-key') === 'shared'
        return { ...base, top: collapsed ? 0 : 200, height: 0 } as DOMRect
      }
      return { ...base, top: 0, height: 0 } as DOMRect
    })

    const { wrapper, store } = await mountDetail()
    store.shared = [makeGame({ id: 's-a', name: 'Apple' })]
    store.theirsOnly = Array.from({ length: 13 }, (_, i) =>
      i === 0 ? makeGame({ id: 't-a', name: 'Avocado' }) : makeGame({ id: `t${i}`, name: `Theirs ${i}` }),
    )
    await flushPromises()

    // Collapses 'shared' (first header) - 'mineOnly' stays expanded but
    // empty, matching the reported "3rd section open, other 2 closed"
    // shape closely enough (an empty expanded section contributes no
    // candidates of its own either way).
    await wrapper.find('.collection-section-header').trigger('click')

    // ALPHABET is ['#', 'A', 'B', ...] - bucket index 1, landing anywhere
    // within the strip's own second 10px slice (10-20 of the mocked
    // 270px/27-bucket strip) resolves to 'A'.
    await wrapper.find('.az-scrubber-buckets').trigger('pointerdown', { clientY: 15, pointerId: 1 })

    expect(scrollIntoView).toHaveBeenCalledTimes(1)
    const scrolledTo = scrollIntoView.mock.instances[0] as unknown as HTMLElement
    expect(scrolledTo.closest('.collection-section')?.getAttribute('data-section-key')).toBe('theirsOnly')

    rectSpy.mockRestore()
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
