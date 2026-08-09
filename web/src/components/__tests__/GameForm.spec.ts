import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import GameForm, { type GameFormData } from '@/components/GameForm.vue'

function reactiveForm(overrides: Partial<GameFormData> = {}): GameFormData {
  return reactive({
    name: '',
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
    props: {
      modelValue,
      submitting: false,
      submitLabel: 'Guardar',
      errors: {},
    },
  })

  return { wrapper, form: modelValue }
}

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
