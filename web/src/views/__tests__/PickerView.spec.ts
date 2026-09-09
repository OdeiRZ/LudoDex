import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PickerView from '@/views/PickerView.vue'
import { useGamesStore, type UserGame } from '@/stores/games'
import { useFriendsStore, type FriendEntry } from '@/stores/friends'
import { makeEntry, makeGame } from '@/stores/__tests__/gameFixtures'
import { i18n } from '@/i18n'

// PickerView calls games.fetchAll()/friends.fetchAll() on mount unless
// each store already reports itself as loaded - marking both loaded
// upfront keeps these tests from making real HTTP requests through the
// stores' axios client (an unmocked one of these is exactly what broke
// CI for App.spec.ts: an unhandled network-error rejection that Vitest
// counts as a run failure even though every individual test still passes).
function mountPicker(entries: UserGame[], friendEntries: FriendEntry[] = []) {
  setActivePinia(createPinia())
  const games = useGamesStore()
  games.collection = entries
  games.loaded = true

  const friends = useFriendsStore()
  friends.friends = friendEntries
  friends.loaded = true

  return mount(PickerView, {
    global: { stubs: { RouterLink: true }, plugins: [i18n] },
  })
}

describe('PickerView', () => {
  // The density preference persists in localStorage across page loads -
  // clear it so one test's toggle doesn't leak into the next.
  beforeEach(() => {
    localStorage.clear()
  })

  it('only lists owned games, never wishlist entries or expansions', () => {
    const owned = makeEntry({ name: 'Root' }, 'owned')
    const wishlisted = makeEntry({ name: 'Ark Nova' }, 'wishlist')
    const expansion = makeEntry({ name: 'Root: Riverfolk', base_game_id: owned.game.id }, 'owned')

    const wrapper = mountPicker([owned, wishlisted, expansion])

    const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names).toEqual(['Root'])
  })

  it('extends the shown/filtered player count with an owned expansion that supports more players', async () => {
    const root = makeEntry({ id: 'root', name: 'Root', min_players: 2, max_players: 4 }, 'owned')
    const riverfolk = makeEntry(
      { name: 'Root: Riverfolk', base_game_id: 'root', min_players: 2, max_players: 6 },
      'owned',
    )

    const wrapper = mountPicker([root, riverfolk])

    expect(wrapper.find('.game-card .meta').text()).toContain('2–6 jugadores')

    await wrapper.find('#players').setValue('6')
    expect(wrapper.findAll('.game-card h2').map((h2) => h2.text())).toEqual(['Root'])

    await wrapper.find('#players').setValue('5')
    expect(wrapper.findAll('.game-card h2').map((h2) => h2.text())).toEqual(['Root'])
  })

  it('does not extend the player count from an expansion that is only wishlisted, not owned', async () => {
    const root = makeEntry({ id: 'root', name: 'Root', min_players: 2, max_players: 4 }, 'owned')
    const riverfolk = makeEntry(
      { name: 'Root: Riverfolk', base_game_id: 'root', min_players: 2, max_players: 6 },
      'wishlist',
    )

    const wrapper = mountPicker([root, riverfolk])

    expect(wrapper.find('.game-card .meta').text()).toContain('2–4 jugadores')

    await wrapper.find('#players').setValue('6')
    expect(wrapper.findAll('.game-card h2')).toHaveLength(0)
  })

  it('shows the campaign badge, and matches the campaign-only filter, from an owned expansion even when the base game has no campaign mode of its own', async () => {
    const spiritIsland = makeEntry({ id: 'si', name: 'Spirit Island', has_campaign: false }, 'owned')
    const natureIncarnate = makeEntry(
      { name: 'Spirit Island: Nature Incarnate', base_game_id: 'si', has_campaign: true },
      'owned',
    )

    const wrapper = mountPicker([spiritIsland, natureIncarnate])

    expect(wrapper.find('.game-card .tags').text()).toContain('Campaña')

    await wrapper.find('input[type="checkbox"]').setValue(true)
    expect(wrapper.findAll('.game-card h2').map((h2) => h2.text())).toEqual(['Spirit Island'])
  })

  it('shows the empty state when nothing is owned yet', () => {
    const wrapper = mountPicker([makeEntry({ name: 'Ark Nova' }, 'wishlist')])

    expect(wrapper.text()).toContain('No tienes juegos marcados como "Lo tengo" todavía.')
  })

  it('shows the publication year alongside players/duration, and omits the row entirely when there is nothing to show', () => {
    const wrapper = mountPicker([
      makeEntry({ name: 'Root', year_published: 2018 }, 'owned'),
      makeEntry({ name: 'Ark Nova', year_published: null }, 'owned'),
    ])

    const cards = wrapper.findAll('.game-card')
    const rootCard = cards.find((card) => card.text().includes('Root'))
    const arkNovaCard = cards.find((card) => card.text().includes('Ark Nova'))

    expect(rootCard?.find('.meta').text()).toContain('2018')
    expect(arkNovaCard?.find('.meta').exists()).toBe(false)
  })

  describe('with a mixed collection', () => {
    let wrapper: ReturnType<typeof mountPicker>

    beforeEach(() => {
      wrapper = mountPicker([
        makeEntry({
          name: 'Root',
          min_players: 2,
          max_players: 4,
          min_playtime_minutes: 60,
          is_cooperative: true,
          is_competitive: true,
          has_campaign: true,
          categories: ['Control de territorio', 'Asimétrico'],
        }),
        makeEntry({
          name: 'Friday',
          min_players: 1,
          max_players: 1,
          min_playtime_minutes: 20,
          is_cooperative: true,
          categories: ['Cartas'],
        }),
        makeEntry({
          name: 'Catan',
          min_players: 3,
          max_players: 4,
          max_playtime_minutes: 25,
          is_competitive: true,
          categories: ['Control de territorio'],
        }),
      ])
    })

    function gameNames() {
      return wrapper.findAll('.game-card h2').map((h2) => h2.text())
    }

    it('defaults the player filter to 2 rather than starting empty', () => {
      expect((wrapper.find('#players').element as HTMLInputElement).value).toBe('2')
      // Only Root (2-4) fits that default; Friday and Catan don't.
      expect(gameNames()).toEqual(['Root'])
    })

    it('shows a count of the currently filtered results, not the full collection', async () => {
      // Default player filter (2) only matches Root.
      expect(wrapper.find('.count').text()).toBe('1 juegos')

      await wrapper.find('#players').setValue('')

      expect(wrapper.find('.count').text()).toBe('3 juegos')
    })

    it('gives each result a details button that opens its description in a modal', async () => {
      await wrapper.find('#players').setValue('')

      const detailButtons = wrapper.findAll('.details-icon-button')

      expect(detailButtons).toHaveLength(3)
      expect(
        detailButtons.every((btn) => btn.attributes('aria-label') === 'Ver detalles'),
      ).toBe(true)

      expect(wrapper.findComponent({ name: 'GameDetailModal' }).exists()).toBe(false)

      await detailButtons[0].trigger('click')

      expect(wrapper.findComponent({ name: 'GameDetailModal' }).exists()).toBe(true)
    })

    it('shows every owned game once the player filter is cleared', async () => {
      await wrapper.find('#players').setValue('')

      // Alphabetical by default, not collection/insertion order.
      expect(gameNames()).toEqual(['Catan', 'Friday', 'Root'])
    })

    it('filters by player count using each game min/max range', async () => {
      await wrapper.find('#players').setValue(4)

      // Root and Catan both allow 4, Friday tops out at 1.
      expect(gameNames()).toEqual(['Catan', 'Root'])
    })

    it('filters by duration using whichever playtime value is set, min preferred', async () => {
      await wrapper.find('#players').setValue('')
      await wrapper.find('input[type="radio"][value="30"]').setValue()

      // Root only has min_playtime_minutes (60) -> excluded. Friday only
      // has min_playtime_minutes (20) -> included. Catan only has
      // max_playtime_minutes (25, no min set) -> falls back to it and is
      // included too - this is the exact shape of data (one playtime
      // value, not a real min/max pair) that made this filter a no-op
      // before, since it only ever checked max_playtime_minutes.
      expect(gameNames()).toEqual(['Catan', 'Friday'])
    })

    it('filters to only games with a campaign when "Modo campaña" is checked', async () => {
      await wrapper.find('#players').setValue('')
      await wrapper.find('input[type="checkbox"]').setValue(true)

      // Only Root has has_campaign: true in this fixture set.
      expect(gameNames()).toEqual(['Root'])
    })

    it('filters by category through the "Género" select', async () => {
      await wrapper.find('#players').setValue('')
      await wrapper.find('#category').setValue('Cartas')

      expect(gameNames()).toEqual(['Friday'])
    })

    it('filters by name, case-insensitively', async () => {
      await wrapper.find('#players').setValue('')
      await wrapper.find('#search').setValue('ROOT')

      expect(gameNames()).toEqual(['Root'])
    })

    it('combines the name search with the other active filters', async () => {
      await wrapper.find('#players').setValue('')
      await wrapper.find('input[type="radio"][value="cooperative"]').setValue()
      await wrapper.find('#search').setValue('a')

      // "a" matches both Friday and Catan by name, but the cooperative
      // filter only leaves Friday - proving both filters apply together.
      expect(gameNames()).toEqual(['Friday'])
    })

    it('only offers categories that appear on an owned game', () => {
      const options = wrapper.findAll('#category option').map((option) => option.text())
      expect(options).toEqual(['Cualquiera', 'Asimétrico', 'Cartas', 'Control de territorio'])
    })

    it('toggles compact density for the results grid, persisting the choice', async () => {
      expect(wrapper.find('.results').classes()).not.toContain('compact')

      await wrapper.find('.density-toggle').trigger('click')

      expect(wrapper.find('.results').classes()).toContain('compact')
      expect(localStorage.getItem('ludodex-collection-density')).toBe('compact')
    })

    it('filters by cooperative/competitive mode', async () => {
      await wrapper.find('#players').setValue('')
      await wrapper.find('input[type="radio"][value="cooperative"]').setValue()

      expect(gameNames()).toEqual(['Friday', 'Root'])
    })

    it('sets players to 1 and hides the mode filter when "Solo" is clicked', async () => {
      expect(wrapper.find('input[type="radio"][value="cooperative"]').exists()).toBe(true)

      await wrapper.find('.players-row button').trigger('click')

      expect((wrapper.find('#players').element as HTMLInputElement).value).toBe('1')
      expect(wrapper.find('input[type="radio"][value="cooperative"]').exists()).toBe(false)
      expect(gameNames()).toEqual(['Friday'])
    })

    it('returns to the default player count when "Solo" is toggled off', async () => {
      const soloButton = wrapper.find('.players-row button')
      await soloButton.trigger('click')
      await soloButton.trigger('click')

      expect((wrapper.find('#players').element as HTMLInputElement).value).toBe('2')
      expect(gameNames()).toEqual(['Root'])
    })

    it('drops an active mode filter once "Solo" is selected', async () => {
      await wrapper.find('#players').setValue('')
      await wrapper.find('input[type="radio"][value="competitive"]').setValue()
      expect(gameNames()).toEqual(['Catan', 'Root'])

      await wrapper.find('.players-row button').trigger('click')

      // Mode radios are hidden and ignored while solo, so Friday (the only
      // game that fits 1 player) is back regardless of the mode picked above.
      expect(gameNames()).toEqual(['Friday'])
    })
  })

  describe('sort controls', () => {
    function gameNames(wrapper: ReturnType<typeof mountPicker>) {
      return wrapper.findAll('.game-card h2').map((h2) => h2.text())
    }

    it('sorts alphabetically by default', async () => {
      const wrapper = mountPicker([makeEntry({ name: 'Root' }), makeEntry({ name: 'Ark Nova' }), makeEntry({ name: 'Catan' })])
      await wrapper.find('#players').setValue('')

      expect(gameNames(wrapper)).toEqual(['Ark Nova', 'Catan', 'Root'])
    })

    it('reverses the order when the sort button is clicked, and back again on a second click', async () => {
      const wrapper = mountPicker([makeEntry({ name: 'Root' }), makeEntry({ name: 'Ark Nova' }), makeEntry({ name: 'Catan' })])
      await wrapper.find('#players').setValue('')

      const sortButton = wrapper.find('.sort-toggle')
      await sortButton.trigger('click')
      expect(gameNames(wrapper)).toEqual(['Root', 'Catan', 'Ark Nova'])

      await sortButton.trigger('click')
      expect(gameNames(wrapper)).toEqual(['Ark Nova', 'Catan', 'Root'])
    })

    it('sorts by BGG rank when that criterion is selected, best rank first', async () => {
      const wrapper = mountPicker([
        makeEntry({ name: 'Mid', bgg_rank: 50 }),
        makeEntry({ name: 'Best', bgg_rank: 1 }),
        makeEntry({ name: 'Worst', bgg_rank: 200 }),
      ])
      await wrapper.find('#players').setValue('')

      await wrapper.find('#sort-criterion').setValue('rank')

      expect(gameNames(wrapper)).toEqual(['Best', 'Mid', 'Worst'])
    })

    it('always sinks games with no BGG rank to the bottom, in either direction', async () => {
      const wrapper = mountPicker([
        makeEntry({ name: 'Unranked', bgg_rank: null }),
        makeEntry({ name: 'Best', bgg_rank: 1 }),
        makeEntry({ name: 'Mid', bgg_rank: 50 }),
      ])
      await wrapper.find('#players').setValue('')

      await wrapper.find('#sort-criterion').setValue('rank')
      expect(gameNames(wrapper)).toEqual(['Best', 'Mid', 'Unranked'])

      await wrapper.find('.sort-toggle').trigger('click')
      expect(gameNames(wrapper)).toEqual(['Mid', 'Best', 'Unranked'])
    })

    it('sorts by publication year when that criterion is selected, oldest first', async () => {
      const wrapper = mountPicker([
        makeEntry({ name: 'Mid', year_published: 2010 }),
        makeEntry({ name: 'Oldest', year_published: 1995 }),
        makeEntry({ name: 'Newest', year_published: 2022 }),
      ])
      await wrapper.find('#players').setValue('')

      await wrapper.find('#sort-criterion').setValue('year')

      expect(gameNames(wrapper)).toEqual(['Oldest', 'Mid', 'Newest'])
    })

    it('always sinks games with no known publication year to the bottom, in either direction', async () => {
      const wrapper = mountPicker([
        makeEntry({ name: 'Unknown', year_published: null }),
        makeEntry({ name: 'Oldest', year_published: 1995 }),
        makeEntry({ name: 'Mid', year_published: 2010 }),
      ])
      await wrapper.find('#players').setValue('')

      await wrapper.find('#sort-criterion').setValue('year')
      expect(gameNames(wrapper)).toEqual(['Oldest', 'Mid', 'Unknown'])

      await wrapper.find('.sort-toggle').trigger('click')
      expect(gameNames(wrapper)).toEqual(['Mid', 'Oldest', 'Unknown'])
    })

    it('keeps sorting applied on top of the active search filter', async () => {
      const wrapper = mountPicker([makeEntry({ name: 'Catan' }), makeEntry({ name: 'Ark Nova' })])
      await wrapper.find('#players').setValue('')

      await wrapper.find('.sort-toggle').trigger('click')
      await wrapper.find('#search').setValue('a')

      expect(gameNames(wrapper)).toEqual(['Catan', 'Ark Nova'])
    })
  })

  describe('genre translation', () => {
    // BGG's category vocabulary is always in English, regardless of the
    // app's own language - unlike the fixtures above (arbitrary strings,
    // not BGG's real names), these use BGG's actual category names to
    // exercise the translation table itself.
    it('shows BGG categories translated to Spanish, still sorted by the translated label', () => {
      const wrapper = mountPicker([
        makeEntry({ name: 'Catan', categories: ['Negotiation'] }),
        makeEntry({ name: 'Root', categories: ['Card Game'] }),
      ])

      const options = wrapper.findAll('#category option').map((option) => option.text())

      // "Juego de cartas" sorts before "Negociación" in Spanish, the
      // opposite of "Card Game"/"Negotiation" in English - proves the sort
      // itself uses the translated label, not the raw stored value.
      expect(options).toEqual(['Cualquiera', 'Juego de cartas', 'Negociación'])
    })

    it('filters by the underlying English value even though the option shows the Spanish label', async () => {
      const wrapper = mountPicker([
        makeEntry({ name: 'Catan', categories: ['Negotiation'] }),
        makeEntry({ name: 'Root', categories: ['Card Game'] }),
      ])

      await wrapper.find('#players').setValue('')
      await wrapper.find('#category').setValue('Card Game')

      const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
      expect(names).toEqual(['Root'])
    })

    it('falls back to the original name for a category not in the translation table', () => {
      const wrapper = mountPicker([makeEntry({ name: 'Homebrew', categories: ['Not A Real BGG Category'] })])

      const options = wrapper.findAll('#category option').map((option) => option.text())
      expect(options).toEqual(['Cualquiera', 'Not A Real BGG Category'])
    })
  })

  describe('expansion badge', () => {
    it('shows an expansion count badge on a base game, counting owned and wishlisted expansions alike', () => {
      const root = makeEntry({ name: 'Root' }, 'owned')
      const expansion = makeEntry({ name: 'Root: Riverfolk', base_game_id: root.game.id }, 'wishlist')

      const wrapper = mountPicker([root, expansion])

      expect(wrapper.find('.game-card').text()).toContain('+1 expansiones')
    })

    it('never shows the badge for a game with no expansions in the collection', () => {
      const wrapper = mountPicker([makeEntry({ name: 'Root' }, 'owned')])

      expect(wrapper.find('.game-card').text()).not.toContain('expansi')
    })
  })

  describe('jugar con un amigo', () => {
    const friendEntry: FriendEntry = {
      id: 10,
      user: { id: 2, name: 'Ana', bgg_username: null, avatar_url: null },
    }

    it('only shows the friend selector when there is at least one accepted friend', () => {
      const withoutFriends = mountPicker([makeEntry({ name: 'Root' }, 'owned')])
      expect(withoutFriends.find('#play-with').exists()).toBe(false)

      const withFriends = mountPicker([makeEntry({ name: 'Root' }, 'owned')], [friendEntry])
      expect(withFriends.find('#play-with').exists()).toBe(true)
    })

    it("mixes both collections once a friend is selected, tagging who owns what", async () => {
      const wrapper = mountPicker([makeEntry({ name: 'Root' }, 'owned')], [friendEntry])
      const friends = useFriendsStore()
      vi.spyOn(friends, 'fetchCollectionComparison').mockResolvedValue({
        friend: friendEntry.user,
        shared: [makeGame({ name: 'Catan' })],
        mineOnly: [makeGame({ name: 'Root' })],
        theirsOnly: [makeGame({ name: 'Wingspan' })],
      })

      await wrapper.find('#play-with').setValue('2')
      await flushPromises()
      await wrapper.find('#players').setValue('')

      const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
      expect(names).toEqual(['Catan', 'Root', 'Wingspan'])

      const cards = wrapper.findAll('.game-card')
      const catanCard = cards.find((card) => card.text().includes('Catan'))
      const rootCard = cards.find((card) => card.text().includes('Root'))
      const wingspanCard = cards.find((card) => card.text().includes('Wingspan'))

      expect(catanCard?.text()).toContain('Compartido')
      expect(wingspanCard?.text()).toContain('De Ana')
      // Yours alone is left unbadged on purpose - no "De ti" clutter.
      expect(rootCard?.text()).not.toContain('Compartido')
      expect(rootCard?.text()).not.toContain('De Ana')
    })

    it('goes back to only your own collection when the friend is deselected', async () => {
      const wrapper = mountPicker([makeEntry({ name: 'Root' }, 'owned')], [friendEntry])
      const friends = useFriendsStore()
      vi.spyOn(friends, 'fetchCollectionComparison').mockResolvedValue({
        friend: friendEntry.user,
        shared: [],
        mineOnly: [],
        theirsOnly: [makeGame({ name: 'Wingspan' })],
      })

      await wrapper.find('#play-with').setValue('2')
      await flushPromises()
      await wrapper.find('#players').setValue('')
      expect(wrapper.findAll('.game-card h2').map((h2) => h2.text())).toEqual(['Wingspan'])

      // Not .setValue('') here: the "no friend" <option> is bound via
      // :value="null" (not a string), so its round trip goes through
      // Vue's own option._value rather than the DOM's stringified value
      // attribute - selecting it by index is what actually exercises
      // that, the same way a real click on it would.
      const select = wrapper.find<HTMLSelectElement>('#play-with')
      select.element.selectedIndex = 0
      await select.trigger('change')
      await flushPromises()

      expect(wrapper.findAll('.game-card h2').map((h2) => h2.text())).toEqual(['Root'])
    })

    it('shows a dedicated empty state when you and your friend share nothing playable', async () => {
      const wrapper = mountPicker([makeEntry({ name: 'Root' }, 'owned')], [friendEntry])
      const friends = useFriendsStore()
      vi.spyOn(friends, 'fetchCollectionComparison').mockResolvedValue({
        friend: friendEntry.user,
        shared: [],
        mineOnly: [],
        theirsOnly: [],
      })

      await wrapper.find('#play-with').setValue('2')
      await flushPromises()

      expect(wrapper.text()).toContain('No coincidís en ningún juego que podáis jugar juntos.')
      expect(wrapper.text()).not.toContain('No tienes juegos marcados como "Lo tengo"')
    })

    it('shows an error state, not a crash, when the friend collection fails to load', async () => {
      const wrapper = mountPicker([makeEntry({ name: 'Root' }, 'owned')], [friendEntry])
      const friends = useFriendsStore()
      vi.spyOn(friends, 'fetchCollectionComparison').mockRejectedValue(new Error('network'))

      await wrapper.find('#play-with').setValue('2')
      await flushPromises()

      expect(wrapper.text()).toContain(
        'No se ha podido cargar esta colección. Inténtalo de nuevo.',
      )
      // Hallazgo de auditoria: antes se mostraba con la clase neutra
      // .empty-state (sin role), indistinguible de un "no hay
      // resultados" normal y sin anunciarse a lectores de pantalla.
      const errorEl = wrapper.find('[role="alert"]')
      expect(errorEl.exists()).toBe(true)
      expect(errorEl.classes()).toContain('alert-error')
    })

    it("keeps a translated description in the friend's game, not just the open modal", async () => {
      const wrapper = mountPicker([makeEntry({ name: 'Root' }, 'owned')], [friendEntry])
      const friends = useFriendsStore()
      const wingspan = makeGame({ name: 'Wingspan', description: 'In English' })
      vi.spyOn(friends, 'fetchCollectionComparison').mockResolvedValue({
        friend: friendEntry.user,
        shared: [],
        mineOnly: [],
        theirsOnly: [wingspan],
      })

      await wrapper.find('#play-with').setValue('2')
      await flushPromises()
      await wrapper.find('#players').setValue('')

      await wrapper.find('.details-icon-button').trigger('click')
      await flushPromises()
      wrapper.findComponent({ name: 'GameDetailModal' }).vm.$emit('translated', 'En español')
      await flushPromises()

      expect(wingspan.description_es).toBe('En español')
    })
  })
})
