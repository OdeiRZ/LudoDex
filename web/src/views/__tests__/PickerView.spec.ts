import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PickerView from '@/views/PickerView.vue'
import { useGamesStore, type Game, type UserGame } from '@/stores/games'

function makeGame(overrides: Partial<Game> = {}): Game {
  return {
    id: overrides.id ?? crypto.randomUUID(),
    bgg_id: null,
    base_game_id: null,
    name: 'Juego',
    image_url: null,
    min_players: null,
    max_players: null,
    min_playtime_minutes: null,
    max_playtime_minutes: null,
    weight: null,
    is_cooperative: false,
    is_competitive: false,
    has_campaign: false,
    mechanics: [],
    categories: [],
    ...overrides,
  }
}

function makeEntry(game: Partial<Game>, status: UserGame['status'] = 'owned'): UserGame {
  const builtGame = makeGame(game)
  return { id: builtGame.id, status, notes: null, game: builtGame }
}

// PickerView calls games.fetchAll() on mount unless the store already
// reports itself as loaded - marking it loaded upfront keeps these tests
// from making a real HTTP request through the store's axios client.
function mountPicker(entries: UserGame[]) {
  setActivePinia(createPinia())
  const store = useGamesStore()
  store.collection = entries
  store.loaded = true

  return mount(PickerView, {
    global: { stubs: { RouterLink: true } },
  })
}

describe('PickerView', () => {
  it('only lists owned games, never wishlist entries or expansions', () => {
    const owned = makeEntry({ name: 'Root' }, 'owned')
    const wishlisted = makeEntry({ name: 'Ark Nova' }, 'wishlist')
    const expansion = makeEntry({ name: 'Root: Riverfolk', base_game_id: owned.game.id }, 'owned')

    const wrapper = mountPicker([owned, wishlisted, expansion])

    const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names).toEqual(['Root'])
  })

  it('shows the empty state when nothing is owned yet', () => {
    const wrapper = mountPicker([makeEntry({ name: 'Ark Nova' }, 'wishlist')])

    expect(wrapper.text()).toContain('No tienes juegos marcados como "Lo tengo" todavía.')
  })

  describe('with a mixed collection', () => {
    let wrapper: ReturnType<typeof mountPicker>

    beforeEach(() => {
      wrapper = mountPicker([
        makeEntry({
          name: 'Root',
          min_players: 2,
          max_players: 4,
          is_cooperative: true,
          is_competitive: true,
          categories: ['Control de territorio', 'Asimétrico'],
        }),
        makeEntry({
          name: 'Friday',
          min_players: 1,
          max_players: 1,
          is_cooperative: true,
          categories: ['Cartas'],
        }),
        makeEntry({
          name: 'Catan',
          min_players: 3,
          max_players: 4,
          is_competitive: true,
          categories: ['Control de territorio'],
        }),
      ])
    })

    function gameNames() {
      return wrapper.findAll('.game-card h2').map((h2) => h2.text())
    }

    it('renders every owned game with no filters applied', () => {
      expect(gameNames()).toEqual(['Root', 'Friday', 'Catan'])
    })

    it('filters by player count using each game min/max range', async () => {
      await wrapper.find('#players').setValue(2)

      // Root fits (2-4), Friday tops out at 1, Catan needs at least 3.
      expect(gameNames()).toEqual(['Root'])
    })

    it('filters by category through the "Género" select', async () => {
      await wrapper.find('#category').setValue('Cartas')

      expect(gameNames()).toEqual(['Friday'])
    })

    it('only offers categories that appear on an owned game', () => {
      const options = wrapper.findAll('#category option').map((option) => option.text())
      expect(options).toEqual(['Cualquiera', 'Asimétrico', 'Cartas', 'Control de territorio'])
    })

    it('filters by cooperative/competitive mode', async () => {
      await wrapper.find('input[type="radio"][value="cooperative"]').setValue()

      expect(gameNames()).toEqual(['Root', 'Friday'])
    })

    it('sets players to 1 and hides the mode filter when "Solo" is clicked', async () => {
      expect(wrapper.find('fieldset').text()).toContain('Modo')

      await wrapper.find('.players-row button').trigger('click')

      expect((wrapper.find('#players').element as HTMLInputElement).value).toBe('1')
      expect(wrapper.text()).not.toContain('Modo')
      expect(gameNames()).toEqual(['Friday'])
    })

    it('clears the "Solo" shortcut on a second click', async () => {
      const soloButton = wrapper.find('.players-row button')
      await soloButton.trigger('click')
      await soloButton.trigger('click')

      expect((wrapper.find('#players').element as HTMLInputElement).value).toBe('')
      expect(gameNames()).toEqual(['Root', 'Friday', 'Catan'])
    })

    it('drops an active mode filter once "Solo" is selected', async () => {
      await wrapper.find('input[type="radio"][value="competitive"]').setValue()
      expect(gameNames()).toEqual(['Root', 'Catan'])

      await wrapper.find('.players-row button').trigger('click')

      // Mode radios are hidden and ignored while solo, so Friday (the only
      // game that fits 1 player) is back regardless of the mode picked above.
      expect(gameNames()).toEqual(['Friday'])
    })
  })
})
