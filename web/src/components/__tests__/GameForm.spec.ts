import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import GameForm, { type GameFormData } from '@/components/GameForm.vue'
import { useGamesStore, type BggGameLookup, type UserGame } from '@/stores/games'
import { makeEntry } from '@/stores/__tests__/gameFixtures'
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
    base_game_id: null,
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
function mountGameForm(
  modelValue: GameFormData,
  options: { entries?: UserGame[]; currentGameId?: string | null } = {},
) {
  setActivePinia(createPinia())
  const games = useGamesStore()
  games.collection = options.entries ?? []

  const wrapper = mount(GameForm, {
    global: { plugins: [i18n] },
    props: {
      modelValue,
      submitting: false,
      submitLabel: 'Guardar',
      errors: {},
      currentGameId: options.currentGameId ?? null,
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
      description: null,
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

  const lookupResult: BggGameLookup = {
    bgg_id: 30549,
    name: 'Pandemic',
    image_url: 'https://example.com/pandemic.jpg',
    description: null,
    year_published: null,
    min_age: null,
    bgg_rank: null,
    rating: null,
    min_players: null,
    max_players: null,
    min_playtime_minutes: null,
    max_playtime_minutes: null,
    weight: null,
    mechanics: [],
    categories: [],
  }

  // BGG's /thing lookup only ever knows the game's canonical name/image,
  // not the (often localized) name/image a profile import pulled from the
  // specific edition someone linked in their own BGG collection -
  // overwriting an already-filled value here would silently lose that.
  it('keeps an existing name instead of overwriting it from BGG, and says so', async () => {
    const { wrapper, form } = mountGameForm(reactiveForm({ bgg_id: 30549, name: 'Pandemia' }))
    const games = useGamesStore()
    vi.spyOn(games, 'lookupBggGame').mockResolvedValue(lookupResult)

    await wrapper.find('.bgg-lookup-row button').trigger('click')
    await flushPromises()

    expect(form.name).toBe('Pandemia')
    expect(wrapper.find('.field-kept-notice').exists()).toBe(true)
  })

  it('fills the name from BGG when there was none set yet, without a kept notice', async () => {
    const { wrapper, form } = mountGameForm(reactiveForm({ bgg_id: 30549 }))
    const games = useGamesStore()
    vi.spyOn(games, 'lookupBggGame').mockResolvedValue(lookupResult)

    await wrapper.find('.bgg-lookup-row button').trigger('click')
    await flushPromises()

    expect(form.name).toBe('Pandemic')
    expect(wrapper.find('.field-kept-notice').exists()).toBe(false)
  })

  it('keeps an existing image URL instead of overwriting it from BGG', async () => {
    const { wrapper, form } = mountGameForm(
      reactiveForm({ bgg_id: 30549, image_url: 'https://example.com/mine.jpg' }),
    )
    const games = useGamesStore()
    vi.spyOn(games, 'lookupBggGame').mockResolvedValue(lookupResult)

    await wrapper.find('.bgg-lookup-row button').trigger('click')
    await flushPromises()

    expect(form.image_url).toBe('https://example.com/mine.jpg')
  })

  it('clears the kept notice once the name is edited by hand', async () => {
    const { wrapper } = mountGameForm(reactiveForm({ bgg_id: 30549, name: 'Pandemia' }))
    const games = useGamesStore()
    vi.spyOn(games, 'lookupBggGame').mockResolvedValue(lookupResult)

    await wrapper.find('.bgg-lookup-row button').trigger('click')
    await flushPromises()
    expect(wrapper.find('.field-kept-notice').exists()).toBe(true)

    await wrapper.find('#name').setValue('Pandemic (edicion espanola)')

    expect(wrapper.find('.field-kept-notice').exists()).toBe(false)
  })
})

describe('GameForm mechanics/genre translation', () => {
  // Confirms GameForm actually wires the real BGG translation tables into
  // TagInput's translate prop (TagInput.spec.ts covers the generic
  // mechanism itself with a fake translate function).
  it('shows already-picked mechanics and genres translated to Spanish', () => {
    const { wrapper } = mountGameForm(
      reactiveForm({ mechanics: ['Worker Placement'], categories: ['Card Game'] }),
    )

    const tagTexts = wrapper.findAll('.tags li').map((li) => li.text())
    expect(tagTexts).toEqual([
      expect.stringContaining('Colocación de trabajadores'),
      expect.stringContaining('Juego de cartas'),
    ])
  })

  // Confirms GameForm wires the real BGG tables into TagInput's normalize
  // prop too, converging a hand-typed Spanish translation on the same
  // English value a BGG import would store (TagInput.spec.ts covers the
  // generic mechanism with a fake normalize function).
  it('normalizes a hand-typed Spanish translation to the matching BGG English term', async () => {
    const { wrapper, form } = mountGameForm(reactiveForm())

    const mechanicsInput = wrapper.find('#mechanics')
    await mechanicsInput.setValue('Colocación de trabajadores')
    await mechanicsInput.trigger('keydown', { key: 'Enter' })

    const categoriesInput = wrapper.find('#categories')
    await categoriesInput.setValue('Juego de cartas')
    await categoriesInput.trigger('keydown', { key: 'Enter' })

    expect(form.mechanics).toEqual(['Worker Placement'])
    expect(form.categories).toEqual(['Card Game'])
  })
})

describe('GameForm base game selector', () => {
  it('offers every game in the collection, sorted by name, with "no es una expansión" as the default', () => {
    const { wrapper } = mountGameForm(reactiveForm(), {
      entries: [makeEntry({ id: 'root', name: 'Root' }), makeEntry({ id: 'catan', name: 'Catan' })],
    })

    const select = wrapper.find('#base_game_id')
    const options = select.findAll('option')
    expect(options.map((o) => o.text())).toEqual(['No es una expansión', 'Catan', 'Root'])

    const selectEl = select.element as HTMLSelectElement
    expect(selectEl.options[selectEl.selectedIndex]?.text).toBe('No es una expansión')
  })

  it('excludes a game that is already an expansion of something else, to avoid a two-level chain', () => {
    const { wrapper } = mountGameForm(reactiveForm(), {
      entries: [
        makeEntry({ id: 'catan', name: 'Catan' }),
        makeEntry({ id: 'seafarers', name: 'Seafarers', base_game_id: 'catan' }),
      ],
    })

    const options = wrapper.find('#base_game_id').findAll('option')
    expect(options.map((o) => o.text())).toEqual(['No es una expansión', 'Catan'])
  })

  it('excludes the game currently being edited from its own base game options', () => {
    const { wrapper } = mountGameForm(reactiveForm(), {
      entries: [makeEntry({ id: 'catan', name: 'Catan' }), makeEntry({ id: 'seafarers', name: 'Seafarers' })],
      currentGameId: 'seafarers',
    })

    const options = wrapper.find('#base_game_id').findAll('option')
    expect(options.map((o) => o.text())).toEqual(['No es una expansión', 'Catan'])
  })

  it('pre-selects the game\'s current base game when editing an already-linked expansion', () => {
    const { wrapper } = mountGameForm(
      reactiveForm({ base_game_id: 'catan' }),
      { entries: [makeEntry({ id: 'catan', name: 'Catan' })] },
    )

    expect((wrapper.find('#base_game_id').element as HTMLSelectElement).value).toBe('catan')
  })

  it('updates the model when a base game is picked', async () => {
    const { wrapper, form } = mountGameForm(reactiveForm(), {
      entries: [makeEntry({ id: 'catan', name: 'Catan' })],
    })

    await wrapper.find('#base_game_id').setValue('catan')

    expect(form.base_game_id).toBe('catan')
  })
})
