import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import TagInput from '@/components/TagInput.vue'
import { i18n } from '@/i18n'

function mountTagInput(
  modelValue: string[] = [],
  suggestions: string[] = [],
  translate?: (value: string) => string,
) {
  return mount(TagInput, {
    global: { plugins: [i18n] },
    props: {
      modelValue,
      suggestions,
      label: 'Mecánicas',
      listId: 'mechanics',
      ...(translate ? { translate } : {}),
    },
  })
}

describe('TagInput', () => {
  it('renders the tags already in modelValue', () => {
    const wrapper = mountTagInput(['Dados', 'Cartas'])

    const tags = wrapper.findAll('.tags li').map((li) => li.text())
    expect(tags).toEqual(['Dados ×', 'Cartas ×'])
  })

  it('does not show any suggestion until the input is focused', async () => {
    const wrapper = mountTagInput([], ['Dados', 'Cartas'])

    expect(wrapper.find('.suggestions').exists()).toBe(false)

    await wrapper.find('input').trigger('focus')

    expect(wrapper.find('.suggestions').exists()).toBe(true)
  })

  it('lists every suggestion on focus, not only once the user starts typing', async () => {
    const wrapper = mountTagInput([], ['Dados', 'Cartas', 'Faroleo'])
    await wrapper.find('input').trigger('focus')

    const options = wrapper.findAll('.suggestions button').map((btn) => btn.text())
    expect(options).toEqual(['Dados', 'Cartas', 'Faroleo'])
  })

  it('narrows the suggestion list as the user types', async () => {
    const wrapper = mountTagInput([], ['Dados', 'Cartas', 'Faroleo'])
    const input = wrapper.find('input')
    await input.trigger('focus')
    await input.setValue('ca')

    const options = wrapper.findAll('.suggestions button').map((btn) => btn.text())
    expect(options).toEqual(['Cartas'])
  })

  it('excludes suggestions that are already selected', async () => {
    const wrapper = mountTagInput(['Dados'], ['Dados', 'Cartas'])
    await wrapper.find('input').trigger('focus')

    const options = wrapper.findAll('.suggestions button').map((btn) => btn.text())
    expect(options).toEqual(['Cartas'])
  })

  it('adds a tag and clears the draft when clicking a suggestion', async () => {
    const wrapper = mountTagInput(['Dados'], ['Dados', 'Cartas'])
    await wrapper.find('input').trigger('focus')

    await wrapper.find('.suggestions button').trigger('mousedown')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['Dados', 'Cartas']])
    expect((wrapper.find('input').element as HTMLInputElement).value).toBe('')
  })

  it('adds free text typed by the user as a new tag on Enter', async () => {
    const wrapper = mountTagInput([], ['Dados'])
    const input = wrapper.find('input')

    await input.setValue('Faroleo')
    await input.trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['Faroleo']])
  })

  it('does not add a duplicate when the typed text matches an existing tag', async () => {
    const wrapper = mountTagInput(['Dados'], [])
    const input = wrapper.find('input')

    await input.setValue('Dados')
    await input.trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('ignores blank input on Enter instead of adding an empty tag', async () => {
    const wrapper = mountTagInput([], [])
    const input = wrapper.find('input')

    await input.setValue('   ')
    await input.trigger('keydown', { key: 'Enter' })

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('commits the draft as a tag on blur, same as pressing Enter', async () => {
    const wrapper = mountTagInput([], [])
    const input = wrapper.find('input')

    await input.setValue('Faroleo')
    await input.trigger('blur')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['Faroleo']])
  })

  it('keeps suggestion buttons out of tab order, so Tab does not focus a button the closing dropdown then removes', async () => {
    const wrapper = mountTagInput([], ['Dados', 'Cartas'])
    await wrapper.find('input').trigger('focus')

    const buttons = wrapper.findAll('.suggestions button')
    expect(buttons.length).toBeGreaterThan(0)
    buttons.forEach((button) => {
      expect(button.attributes('tabindex')).toBe('-1')
    })
  })

  it('removes a tag when its remove button is clicked', async () => {
    const wrapper = mountTagInput(['Dados', 'Cartas'], [])

    const removeButtons = wrapper.findAll('.tags button')
    await removeButtons[0]!.trigger('click')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['Cartas']])
  })

  describe('translate prop', () => {
    const translate = (value: string) => (value === 'Card Game' ? 'Juego de cartas' : value)

    it('shows the translated label for an already-added tag, storing the raw value untouched', () => {
      const wrapper = mountTagInput(['Card Game'], [], translate)

      expect(wrapper.find('.tags li').text()).toContain('Juego de cartas')
      expect(wrapper.find('.tags li').text()).not.toContain('Card Game')
    })

    it('shows the translated label on a suggestion button, but adds the raw value on click', async () => {
      const wrapper = mountTagInput([], ['Card Game'], translate)
      await wrapper.find('input').trigger('focus')

      expect(wrapper.find('.suggestions button').text()).toBe('Juego de cartas')

      await wrapper.find('.suggestions button').trigger('mousedown')

      expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['Card Game']])
    })

    it('matches a suggestion by its translated label, not just the raw stored value', async () => {
      const wrapper = mountTagInput([], ['Card Game'], translate)
      const input = wrapper.find('input')
      await input.trigger('focus')
      await input.setValue('cartas')

      const options = wrapper.findAll('.suggestions button').map((btn) => btn.text())
      expect(options).toEqual(['Juego de cartas'])
    })
  })
})
