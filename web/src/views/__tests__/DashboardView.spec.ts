import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import DashboardView from '@/views/DashboardView.vue'
import { useGamesStore } from '@/stores/games'
import { useToastStore } from '@/stores/toast'
import { makeEntry } from '@/stores/__tests__/gameFixtures'
import { i18n } from '@/i18n'

// Pre-seeding the store as already loaded keeps onMounted from firing a
// real network request through fetchAll().
function mountDashboard(entries: ReturnType<typeof makeEntry>[]) {
  setActivePinia(createPinia())

  const games = useGamesStore()
  games.collection = entries
  games.loaded = true

  const wrapper = mount(DashboardView, {
    global: { stubs: { RouterLink: true }, plugins: [i18n] },
  })

  return { wrapper, games }
}

describe('DashboardView', () => {
  // The density preference persists in localStorage across page loads -
  // clear it so one test's toggle doesn't leak into the next.
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows the empty state when the collection has no games', () => {
    const { wrapper } = mountDashboard([])

    expect(wrapper.text()).toContain('Todavía no has añadido ningún juego.')
  })

  it('does not show mechanics on the card - that detail belongs to the edit form, not a collection glance', () => {
    const { wrapper } = mountDashboard([makeEntry({ name: 'Root', mechanics: ['Control de área'] })])

    expect(wrapper.text()).not.toContain('Control de área')
  })

  it('shows the publication year alongside players/duration, and omits the row entirely when there is nothing to show', () => {
    const { wrapper } = mountDashboard([
      makeEntry({ name: 'Root', year_published: 2018 }),
      makeEntry({ name: 'Ark Nova', year_published: null }),
    ])

    const cards = wrapper.findAll('.game-card')
    const rootCard = cards.find((card) => card.text().includes('Root'))
    const arkNovaCard = cards.find((card) => card.text().includes('Ark Nova'))

    expect(rootCard?.find('.meta').text()).toContain('2018')
    expect(arkNovaCard?.find('.meta').exists()).toBe(false)
  })

  it('uses a different icon path for the owned vs wishlist status badge', () => {
    const owned = makeEntry({ name: 'Root' }, 'owned')
    const wishlisted = makeEntry({ name: 'Ark Nova' }, 'wishlist')

    const { wrapper } = mountDashboard([owned, wishlisted])

    const cards = wrapper.findAll('.game-card')
    const rootCard = cards.find((card) => card.text().includes('Root'))
    const arkNovaCard = cards.find((card) => card.text().includes('Ark Nova'))

    const rootIconPath = rootCard?.find('.badge-primary .badge-icon path').attributes('d')
    const arkNovaIconPath = arkNovaCard?.find('.badge-accent .badge-icon path').attributes('d')

    expect(rootIconPath).toBeTruthy()
    expect(arkNovaIconPath).toBeTruthy()
    expect(rootIconPath).not.toBe(arkNovaIconPath)
  })

  it('lists every entry in the collection, owned or wishlisted', () => {
    const owned = makeEntry({ name: 'Root' }, 'owned')
    const wishlisted = makeEntry({ name: 'Ark Nova' }, 'wishlist')

    const { wrapper } = mountDashboard([owned, wishlisted])

    // Alphabetical by default, not insertion order - see the sort tests
    // below for the toggle itself.
    const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names).toEqual(['Ark Nova', 'Root'])
  })

  it('reverses the order when the sort button is clicked, and back again on a second click', async () => {
    const { wrapper } = mountDashboard([
      makeEntry({ name: 'Root' }),
      makeEntry({ name: 'Ark Nova' }),
      makeEntry({ name: 'Catan' }),
    ])

    const names = () => wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names()).toEqual(['Ark Nova', 'Catan', 'Root'])

    const sortButton = wrapper.find('.sort-toggle')
    await sortButton.trigger('click')
    expect(names()).toEqual(['Root', 'Catan', 'Ark Nova'])

    await sortButton.trigger('click')
    expect(names()).toEqual(['Ark Nova', 'Catan', 'Root'])
  })

  it('sorts by BGG rank when that criterion is selected, best rank first', async () => {
    const { wrapper } = mountDashboard([
      makeEntry({ name: 'Mid', bgg_rank: 50 }),
      makeEntry({ name: 'Best', bgg_rank: 1 }),
      makeEntry({ name: 'Worst', bgg_rank: 200 }),
    ])

    await wrapper.find('.sort-criterion').setValue('rank')

    const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names).toEqual(['Best', 'Mid', 'Worst'])
  })

  it('reverses the rank order (worst first) when the sort button is clicked', async () => {
    const { wrapper } = mountDashboard([
      makeEntry({ name: 'Mid', bgg_rank: 50 }),
      makeEntry({ name: 'Best', bgg_rank: 1 }),
      makeEntry({ name: 'Worst', bgg_rank: 200 }),
    ])

    await wrapper.find('.sort-criterion').setValue('rank')
    await wrapper.find('.sort-toggle').trigger('click')

    const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names).toEqual(['Worst', 'Mid', 'Best'])
  })

  it('always sinks games with no BGG rank to the bottom, in either direction', async () => {
    const { wrapper } = mountDashboard([
      makeEntry({ name: 'Unranked', bgg_rank: null }),
      makeEntry({ name: 'Best', bgg_rank: 1 }),
      makeEntry({ name: 'Mid', bgg_rank: 50 }),
    ])

    await wrapper.find('.sort-criterion').setValue('rank')
    const names = () => wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names()).toEqual(['Best', 'Mid', 'Unranked'])

    await wrapper.find('.sort-toggle').trigger('click')
    expect(names()).toEqual(['Mid', 'Best', 'Unranked'])
  })

  it('sorts by publication year when that criterion is selected, oldest first', async () => {
    const { wrapper } = mountDashboard([
      makeEntry({ name: 'Mid', year_published: 2010 }),
      makeEntry({ name: 'Oldest', year_published: 1995 }),
      makeEntry({ name: 'Newest', year_published: 2022 }),
    ])

    await wrapper.find('.sort-criterion').setValue('year')

    const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names).toEqual(['Oldest', 'Mid', 'Newest'])
  })

  it('reverses the year order (newest first) when the sort button is clicked', async () => {
    const { wrapper } = mountDashboard([
      makeEntry({ name: 'Mid', year_published: 2010 }),
      makeEntry({ name: 'Oldest', year_published: 1995 }),
      makeEntry({ name: 'Newest', year_published: 2022 }),
    ])

    await wrapper.find('.sort-criterion').setValue('year')
    await wrapper.find('.sort-toggle').trigger('click')

    const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names).toEqual(['Newest', 'Mid', 'Oldest'])
  })

  it('always sinks games with no known publication year to the bottom, in either direction', async () => {
    const { wrapper } = mountDashboard([
      makeEntry({ name: 'Unknown', year_published: null }),
      makeEntry({ name: 'Oldest', year_published: 1995 }),
      makeEntry({ name: 'Mid', year_published: 2010 }),
    ])

    await wrapper.find('.sort-criterion').setValue('year')
    const names = () => wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names()).toEqual(['Oldest', 'Mid', 'Unknown'])

    await wrapper.find('.sort-toggle').trigger('click')
    expect(names()).toEqual(['Mid', 'Oldest', 'Unknown'])
  })

  it('keeps sorting applied on top of the active search filter', async () => {
    const { wrapper } = mountDashboard([
      makeEntry({ name: 'Catan' }),
      makeEntry({ name: 'Ark Nova' }),
    ])

    await wrapper.find('.sort-toggle').trigger('click')
    await wrapper.find('input[type="search"]').setValue('a')

    const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names).toEqual(['Catan', 'Ark Nova'])
  })

  it('shows the collection count next to the title', () => {
    const { wrapper } = mountDashboard([makeEntry({ name: 'Root' }), makeEntry({ name: 'Ark Nova' })])

    expect(wrapper.find('.count').text()).toBe('2 juegos')
  })

  it('filters the collection by name and updates the count to match', async () => {
    const { wrapper } = mountDashboard([
      makeEntry({ name: 'Root' }),
      makeEntry({ name: 'Ark Nova' }),
      makeEntry({ name: 'Arkham Horror' }),
    ])

    await wrapper.find('input[type="search"]').setValue('ark')

    const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names).toEqual(['Ark Nova', 'Arkham Horror'])
    expect(wrapper.find('.count').text()).toBe('2 juegos')
  })

  it('shows a no-matches message when the search matches nothing', async () => {
    const { wrapper } = mountDashboard([makeEntry({ name: 'Root' })])

    await wrapper.find('input[type="search"]').setValue('nonexistent')

    expect(wrapper.text()).toContain('Ningún juego de tu colección encaja con estos filtros.')
    expect(wrapper.findAll('.game-card')).toHaveLength(0)
  })

  describe('filtering by base game / expansion', () => {
    function names(wrapper: ReturnType<typeof mountDashboard>['wrapper']) {
      return wrapper.findAll('.game-card h2').map((h2) => h2.text())
    }

    it('shows everything by default', () => {
      const { wrapper } = mountDashboard([
        makeEntry({ id: 'root', name: 'Root' }),
        makeEntry({ name: 'Root: Riverfolk', base_game_id: 'root' }),
      ])

      expect(names(wrapper)).toEqual(['Root', 'Root: Riverfolk'])
    })

    it('shows only base games when that option is picked', async () => {
      const { wrapper } = mountDashboard([
        makeEntry({ id: 'root', name: 'Root' }),
        makeEntry({ name: 'Root: Riverfolk', base_game_id: 'root' }),
      ])

      await wrapper.find('.type-filter').setValue('base')

      expect(names(wrapper)).toEqual(['Root'])
    })

    it('shows only expansions when that option is picked', async () => {
      const { wrapper } = mountDashboard([
        makeEntry({ id: 'root', name: 'Root' }),
        makeEntry({ name: 'Root: Riverfolk', base_game_id: 'root' }),
      ])

      await wrapper.find('.type-filter').setValue('expansion')

      expect(names(wrapper)).toEqual(['Root: Riverfolk'])
    })

    it('combines the type filter with the active search', async () => {
      const { wrapper } = mountDashboard([
        makeEntry({ id: 'root', name: 'Root' }),
        makeEntry({ name: 'Root: Riverfolk', base_game_id: 'root' }),
        makeEntry({ name: 'Catan' }),
      ])

      await wrapper.find('.type-filter').setValue('base')
      await wrapper.find('input[type="search"]').setValue('root')

      expect(names(wrapper)).toEqual(['Root'])
    })

    it('adds an expansions count in parentheses to the header while "Todos" is selected', () => {
      const { wrapper } = mountDashboard([
        makeEntry({ id: 'root', name: 'Root' }),
        makeEntry({ name: 'Root: Riverfolk', base_game_id: 'root' }),
        makeEntry({ name: 'Catan' }),
      ])

      expect(wrapper.find('.count').text()).toBe('3 juegos (1 expansiones)')
    })

    it('omits the expansions parenthetical once the type filter is no longer "Todos"', async () => {
      const { wrapper } = mountDashboard([
        makeEntry({ id: 'root', name: 'Root' }),
        makeEntry({ name: 'Root: Riverfolk', base_game_id: 'root' }),
      ])

      await wrapper.find('.type-filter').setValue('expansion')

      expect(wrapper.find('.count').text()).toBe('1 juegos')
    })

    it('omits the expansions parenthetical when the collection has no expansions at all', () => {
      const { wrapper } = mountDashboard([makeEntry({ name: 'Catan' })])

      expect(wrapper.find('.count').text()).toBe('1 juegos')
    })

    it('counts only expansions matching the active search, not the whole collection', async () => {
      const { wrapper } = mountDashboard([
        makeEntry({ id: 'root', name: 'Root' }),
        makeEntry({ name: 'Root: Riverfolk', base_game_id: 'root' }),
        makeEntry({ id: 'catan', name: 'Catan' }),
        makeEntry({ name: 'Catan: Seafarers', base_game_id: 'catan' }),
      ])

      await wrapper.find('input[type="search"]').setValue('root')

      expect(wrapper.find('.count').text()).toBe('2 juegos (1 expansiones)')
    })

    it('never shows a BGG rank badge on an expansion, even if one has a bgg_id/rank of its own', () => {
      const { wrapper } = mountDashboard([
        makeEntry({ id: 'root', name: 'Root', bgg_id: 1 }),
        makeEntry({ name: 'Root: Riverfolk', base_game_id: 'root', bgg_id: 2, bgg_rank: 500 }),
      ])

      const cards = wrapper.findAll('.game-card')
      expect(cards[0].text()).toContain('en BGG')
      expect(cards[1].text()).not.toContain('en BGG')
    })

    it('marks only expansion cards with the visual expansion border', () => {
      const { wrapper } = mountDashboard([
        makeEntry({ id: 'root', name: 'Root' }),
        makeEntry({ name: 'Root: Riverfolk', base_game_id: 'root' }),
      ])

      const cards = wrapper.findAll('.game-card')
      expect(cards[0].find('.game-cover').classes()).not.toContain('expansion')
      expect(cards[1].find('.game-cover').classes()).toContain('expansion')
    })

    it('disables the "sort by rank" option while only expansions are shown, and re-enables it otherwise', async () => {
      const { wrapper } = mountDashboard([
        makeEntry({ id: 'root', name: 'Root', bgg_id: 1 }),
        makeEntry({ name: 'Root: Riverfolk', base_game_id: 'root' }),
      ])

      const rankOption = () =>
        wrapper.find('.sort-criterion').findAll('option').find((o) => o.element.value === 'rank')
      expect(rankOption()?.element.disabled).toBe(false)

      await wrapper.find('.type-filter').setValue('expansion')
      expect(rankOption()?.element.disabled).toBe(true)

      await wrapper.find('.type-filter').setValue('all')
      expect(rankOption()?.element.disabled).toBe(false)
    })

    it('falls back to sorting by name if rank was selected and the filter switches to expansions only', async () => {
      const { wrapper } = mountDashboard([
        makeEntry({ id: 'root', name: 'Root', bgg_id: 1 }),
        makeEntry({ name: 'Root: Riverfolk', base_game_id: 'root' }),
      ])

      await wrapper.find('.sort-criterion').setValue('rank')
      await wrapper.find('.type-filter').setValue('expansion')

      expect((wrapper.find('.sort-criterion').element as HTMLSelectElement).value).toBe('name')
    })
  })

  it('defaults to comfortable density and switches to compact on toggle, persisting the choice', async () => {
    const { wrapper } = mountDashboard([makeEntry({ name: 'Root' })])

    expect(wrapper.find('.games').classes()).not.toContain('compact')

    await wrapper.find('.density-toggle').trigger('click')

    expect(wrapper.find('.games').classes()).toContain('compact')
    expect(localStorage.getItem('ludodex-collection-density')).toBe('compact')
  })

  it('starts in compact density when that was the last stored choice', () => {
    localStorage.setItem('ludodex-collection-density', 'compact')

    const { wrapper } = mountDashboard([makeEntry({ name: 'Root' })])

    expect(wrapper.find('.games').classes()).toContain('compact')
  })

  describe('removing a game', () => {
    it('does not remove on the first click, but arms a confirmation instead', async () => {
      const entry = makeEntry({ name: 'Root' })
      const { wrapper, games } = mountDashboard([entry])
      vi.spyOn(games, 'deleteGame').mockResolvedValue()

      await wrapper.find('.card-actions .btn-danger').trigger('click')

      expect(games.deleteGame).not.toHaveBeenCalled()
      expect(wrapper.find('.card-actions .btn-danger').text()).toContain('¿Seguro?')
    })

    it('removes the game on a second click, showing a confirmation toast', async () => {
      const entry = makeEntry({ name: 'Root' })
      const { wrapper, games } = mountDashboard([entry])
      vi.spyOn(games, 'deleteGame').mockResolvedValue()

      const button = wrapper.find('.card-actions .btn-danger')
      await button.trigger('click')
      await button.trigger('click')
      await flushPromises()

      expect(games.deleteGame).toHaveBeenCalledWith(entry.id)
      expect(useToastStore().message).toBe('Juego quitado de tu colección.')
    })

    it('reverts to the normal label if the second click never comes', async () => {
      const { wrapper } = mountDashboard([makeEntry({ name: 'Root' })])

      // Fake timers from here on, so the setTimeout the click below arms is
      // the one actually mocked (same reasoning as EditGameView's own
      // equivalent test - mountDashboard itself must run on real timers).
      vi.useFakeTimers()

      await wrapper.find('.card-actions .btn-danger').trigger('click')
      expect(wrapper.find('.card-actions .btn-danger').text()).toContain('¿Seguro?')

      vi.advanceTimersByTime(4000)
      await wrapper.vm.$nextTick()
      vi.useRealTimers()

      expect(wrapper.find('.card-actions .btn-danger').text()).not.toContain('¿Seguro?')
      expect(wrapper.find('.card-actions .btn-danger').text()).toContain('Quitar')
    })

    it('arming one card\'s confirmation does not arm or remove a different card', async () => {
      const root = makeEntry({ name: 'Root' })
      const catan = makeEntry({ name: 'Catan' })
      const { wrapper, games } = mountDashboard([root, catan])
      vi.spyOn(games, 'deleteGame').mockResolvedValue()

      function removeButtonFor(name: string) {
        const card = wrapper.findAll('.game-card').find((c) => c.text().includes(name))
        return card!.find('.card-actions .btn-danger')
      }

      await removeButtonFor('Root').trigger('click')
      // Clicking Catan's own button while Root's is still armed should arm
      // Catan instead of treating it as Root's confirming click.
      await removeButtonFor('Catan').trigger('click')

      expect(games.deleteGame).not.toHaveBeenCalled()
      expect(removeButtonFor('Root').text()).not.toContain('¿Seguro?')
      expect(removeButtonFor('Catan').text()).toContain('¿Seguro?')
    })
  })

  describe('expansion badges', () => {
    it('shows an expansion count badge on a base game that has expansions in the collection', () => {
      const catan = makeEntry({ id: 'catan', name: 'Catan' })
      const seafarers = makeEntry({ base_game_id: 'catan', name: 'Catan: Seafarers' })

      const { wrapper } = mountDashboard([catan, seafarers])

      const catanCard = wrapper.findAll('.game-card').find((card) => card.text().includes('Catan') && !card.text().includes('Seafarers'))
      expect(catanCard?.text()).toContain('+1 expansiones')
    })

    it('shows an "Expansión de X" badge on the expansion itself, not a count', () => {
      const catan = makeEntry({ id: 'catan', name: 'Catan' })
      const seafarers = makeEntry({ base_game_id: 'catan', base_game_name: 'Catan', name: 'Catan: Seafarers' })

      const { wrapper } = mountDashboard([catan, seafarers])

      const seafarersCard = wrapper.findAll('.game-card').find((card) => card.text().includes('Seafarers'))
      expect(seafarersCard?.text()).toContain('Expansión de Catan')
      expect(seafarersCard?.text()).not.toContain('expansiones')
    })

    it('shows neither badge for a standalone game with no base game and no expansions', () => {
      const { wrapper } = mountDashboard([makeEntry({ name: 'Root' })])

      expect(wrapper.find('.game-card').text()).not.toContain('expansi')
    })
  })

  describe('clearing the whole library', () => {
    it('does not show the confirmation panel by default', () => {
      const { wrapper } = mountDashboard([makeEntry({ name: 'Root' }), makeEntry({ name: 'Ark Nova' })])

      expect(wrapper.find('.clear-confirm').exists()).toBe(false)
    })

    it('shows the confirmation panel with the count once "Vaciar biblioteca" is clicked', async () => {
      const { wrapper } = mountDashboard([makeEntry({ name: 'Root' }), makeEntry({ name: 'Ark Nova' })])

      await wrapper.find('.clear-library-btn').trigger('click')

      expect(wrapper.find('.clear-confirm').text()).toContain('2')
    })

    it('keeps the confirm button disabled until the typed text matches the exact count', async () => {
      const { wrapper } = mountDashboard([makeEntry({ name: 'Root' })])

      await wrapper.find('.clear-library-btn').trigger('click')
      const confirmButton = wrapper.find('.clear-confirm-row .btn-danger')
      expect(confirmButton.attributes('disabled')).toBeDefined()

      await wrapper.find('#clear-confirm-input').setValue('2')
      expect(confirmButton.attributes('disabled')).toBeDefined()

      await wrapper.find('#clear-confirm-input').setValue('1')
      expect(confirmButton.attributes('disabled')).toBeUndefined()
    })

    it('clears the collection once the count is confirmed', async () => {
      const { wrapper, games } = mountDashboard([makeEntry({ name: 'Root' }), makeEntry({ name: 'Ark Nova' })])
      vi.spyOn(games, 'clearCollection').mockResolvedValue()

      await wrapper.find('.clear-library-btn').trigger('click')
      await wrapper.find('#clear-confirm-input').setValue('2')
      await wrapper.find('.clear-confirm-row .btn-danger').trigger('click')
      await flushPromises()

      expect(games.clearCollection).toHaveBeenCalledOnce()
      expect(useToastStore().message).toBe('Colección vaciada.')
      expect(wrapper.find('.clear-confirm').exists()).toBe(false)
    })

    it('does nothing and stays open when "Vaciar biblioteca" is clicked with a wrong count', async () => {
      const { wrapper, games } = mountDashboard([makeEntry({ name: 'Root' })])
      vi.spyOn(games, 'clearCollection').mockResolvedValue()

      await wrapper.find('.clear-library-btn').trigger('click')
      await wrapper.find('#clear-confirm-input').setValue('99')
      await wrapper.find('.clear-confirm-row .btn-danger').trigger('click')
      await flushPromises()

      expect(games.clearCollection).not.toHaveBeenCalled()
      expect(wrapper.find('.clear-confirm').exists()).toBe(true)
    })

    it('closes the panel without clearing anything when cancelled', async () => {
      const { wrapper, games } = mountDashboard([makeEntry({ name: 'Root' })])
      vi.spyOn(games, 'clearCollection').mockResolvedValue()

      await wrapper.find('.clear-library-btn').trigger('click')
      await wrapper.find('.clear-confirm-row').findAll('.btn').filter((b) => !b.classes('btn-danger'))[0].trigger('click')

      expect(games.clearCollection).not.toHaveBeenCalled()
      expect(wrapper.find('.clear-confirm').exists()).toBe(false)
    })
  })

  it('gives each card a details button, alongside (not instead of) Editar/Quitar', async () => {
    const { wrapper } = mountDashboard([makeEntry({ name: 'Root' })])

    const detailsButton = wrapper.find('.details-icon-button')
    expect(detailsButton.exists()).toBe(true)
    expect(detailsButton.attributes('aria-label')).toBe('Ver detalles')
    expect(wrapper.findAll('.card-actions .btn')).toHaveLength(2)

    expect(wrapper.findComponent({ name: 'GameDetailModal' }).exists()).toBe(false)

    await detailsButton.trigger('click')

    expect(wrapper.findComponent({ name: 'GameDetailModal' }).exists()).toBe(true)
  })
})
