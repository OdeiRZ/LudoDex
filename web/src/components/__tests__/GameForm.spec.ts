import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import GameForm, { type GameFormData } from '@/components/GameForm.vue'
import { useGamesStore, type BggGameLookup } from '@/stores/games'
import { i18n } from '@/i18n'

function reactiveForm(overrides: Partial<GameFormData> = {}): GameFormData {
  return reactive({
    name: '',
    image_url: null,
    bgg_id: null,
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
    is_competitive: false,
    has_campaign: false,
    mechanics: [],
    categories: [],
    status: 'owned',
    ...overrides,
  })
}

// GameForm is used via `v-model="form"` with a reactive() object on the
// parent side (AddGameView/EditGameView), never a plain object - the mode
// radio mutates fields on that shared object directly rather than
// reassigning the whole model, so tests pass a reactive() object too and
// assert on it, the same way the real parent views observe changes.
function mountGameForm(modelValue: GameFormData) {
  setActivePinia(createPinia())

  const wrapper = mount(GameForm, {
    global: { plugins: [i18n] },
    props: {
      modelValue,
      submitting: false,
      submitLabel: 'Guardar',
      errors: {},
    },
  })

  return { wrapper, form: modelValue }
}

describe('GameForm weight field', () => {
  // BGG's own weight/complexity data (and the CSV importer, which rounds to
  // 2 decimals) commonly has two decimal places (e.g. 3.64) - a step of
  // "0.1" only allows one, so the native number input rejected those
  // values as a step mismatch even though the backend itself accepts any
  // numeric value here.
  it('accepts a two-decimal value like BGG reports (step allows hundredths)', () => {
    const { wrapper } = mountGameForm(reactiveForm())

    const input = wrapper.find('#weight')
    expect(input.attributes('step')).toBe('0.01')
  })
})

describe('GameForm mode radio', () => {
  it('has no option selected when both flags start false', () => {
    const { wrapper } = mountGameForm(reactiveForm())

    const checked = wrapper
      .findAll('fieldset input[type="radio"]')
      .filter((input) => (input.element as HTMLInputElement).checked)
    expect(checked).toHaveLength(0)
  })

  it('preselects "Ambos" when a game is already both cooperative and competitive', () => {
    const { wrapper } = mountGameForm(reactiveForm({ is_cooperative: true, is_competitive: true }))

    const both = wrapper.find('input[type="radio"][value="both"]')
    expect((both.element as HTMLInputElement).checked).toBe(true)
  })

  it('picking "Cooperativo" sets is_cooperative and clears is_competitive', async () => {
    const { wrapper, form } = mountGameForm(reactiveForm({ is_competitive: true }))

    await wrapper.find('input[type="radio"][value="cooperative"]').setValue()

    expect(form.is_cooperative).toBe(true)
    expect(form.is_competitive).toBe(false)
  })

  it('picking "Competitivo" sets is_competitive and clears is_cooperative', async () => {
    const { wrapper, form } = mountGameForm(reactiveForm({ is_cooperative: true }))

    await wrapper.find('input[type="radio"][value="competitive"]').setValue()

    expect(form.is_cooperative).toBe(false)
    expect(form.is_competitive).toBe(true)
  })

  it('picking "Ambos" sets both flags to true', async () => {
    const { wrapper, form } = mountGameForm(reactiveForm())

    await wrapper.find('input[type="radio"][value="both"]').setValue()

    expect(form.is_cooperative).toBe(true)
    expect(form.is_competitive).toBe(true)
  })
})

describe('GameForm BGG lookup', () => {
  it('fills year, recommended age, rank and rating from the lookup result, alongside the existing fields', async () => {
    const { wrapper, form } = mountGameForm(reactiveForm({ bgg_id: 30549 }))
    const games = useGamesStore()
    const lookupResult: BggGameLookup = {
      bgg_id: 30549,
      name: 'Pandemic',
      image_url: 'https://example.com/pandemic.jpg',
      year_published: 2008,
      min_age: '8+',
      bgg_rank: 174,
      rating: 7.51,
      min_players: 2,
      max_players: 4,
      min_playtime_minutes: 45,
      max_playtime_minutes: 45,
      weight: 2.4,
      mechanics: [],
      categories: [],
    }
    vi.spyOn(games, 'lookupBggGame').mockResolvedValue(lookupResult)

    await wrapper.find('.bgg-lookup-row button').trigger('click')
    await flushPromises()

    expect(form.year_published).toBe(2008)
    expect(form.min_age).toBe('8+')
    expect(form.bgg_rank).toBe(174)
    expect(form.rating).toBe(7.51)
  })
})
