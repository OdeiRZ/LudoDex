import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import TagInput from '@/components/TagInput.vue'

function mountTagInput(modelValue: string[] = [], suggestions: string[] = []) {
  return mount(TagInput, {
    props: {
      modelValue,
      suggestions,
      label: 'Mecánicas',
      listId: 'mechanics',
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

  it('removes a tag when its remove button is clicked', async () => {
    const wrapper = mountTagInput(['Dados', 'Cartas'], [])

    const removeButtons = wrapper.findAll('.tags button')
    await removeButtons[0]!.trigger('click')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['Cartas']])
  })
})
