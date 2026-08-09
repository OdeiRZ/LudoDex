import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PickerView from '@/views/PickerView.vue'
import { useGamesStore, type UserGame } from '@/stores/games'
import { makeEntry } from '@/stores/__tests__/gameFixtures'

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

    it('gives each result an edit button so games can be fixed without leaving the page', async () => {
      await wrapper.find('#players').setValue('')

      const editButtons = wrapper.findAll('.edit-icon-button')

      expect(editButtons).toHaveLength(3)
      expect(editButtons.every((btn) => btn.attributes('aria-label') === 'Editar juego')).toBe(
        true,
      )
    })

    it('shows every owned game once the player filter is cleared', async () => {
      await wrapper.find('#players').setValue('')

      expect(gameNames()).toEqual(['Root', 'Friday', 'Catan'])
    })

    it('filters by player count using each game min/max range', async () => {
      await wrapper.find('#players').setValue(4)

      // Root and Catan both allow 4, Friday tops out at 1.
      expect(gameNames()).toEqual(['Root', 'Catan'])
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
      expect(gameNames()).toEqual(['Friday', 'Catan'])
    })

    it('filters to only games with a campaign when "Solo modo campaña" is checked', async () => {
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

    it('only offers categories that appear on an owned game', () => {
      const options = wrapper.findAll('#category option').map((option) => option.text())
      expect(options).toEqual(['Cualquiera', 'Asimétrico', 'Cartas', 'Control de territorio'])
    })

    it('filters by cooperative/competitive mode', async () => {
      await wrapper.find('#players').setValue('')
      await wrapper.find('input[type="radio"][value="cooperative"]').setValue()

      expect(gameNames()).toEqual(['Root', 'Friday'])
    })

    it('sets players to 1 and hides the mode filter when "Solo" is clicked', async () => {
      expect(wrapper.text()).toContain('Modo')

      await wrapper.find('.players-row button').trigger('click')

      expect((wrapper.find('#players').element as HTMLInputElement).value).toBe('1')
      expect(wrapper.text()).not.toContain('Modo')
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
      expect(gameNames()).toEqual(['Root', 'Catan'])

      await wrapper.find('.players-row button').trigger('click')

      // Mode radios are hidden and ignored while solo, so Friday (the only
      // game that fits 1 player) is back regardless of the mode picked above.
      expect(gameNames()).toEqual(['Friday'])
    })
  })
})
