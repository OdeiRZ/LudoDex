import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PasswordInput from '@/components/PasswordInput.vue'
import { i18n } from '@/i18n'

function mountPasswordInput(modelValue = '') {
  return mount(PasswordInput, {
    global: { plugins: [i18n] },
    props: { id: 'password', modelValue },
  })
}

describe('PasswordInput', () => {
  it('renders as a password field by default', () => {
    const wrapper = mountPasswordInput()

    expect(wrapper.find('input').attributes('type')).toBe('password')
  })

  it('reveals the value as plain text when the toggle is clicked', async () => {
    const wrapper = mountPasswordInput('secreto123')

    await wrapper.find('button').trigger('click')

    expect(wrapper.find('input').attributes('type')).toBe('text')
  })

  it('hides the value again on a second click', async () => {
    const wrapper = mountPasswordInput('secreto123')
    const button = wrapper.find('button')

    await button.trigger('click')
    await button.trigger('click')

    expect(wrapper.find('input').attributes('type')).toBe('password')
  })

  it('binds the modelValue to the input', () => {
    const wrapper = mountPasswordInput('secreto123')

    expect((wrapper.find('input').element as HTMLInputElement).value).toBe('secreto123')
  })

  it('emits update:modelValue when typed into', async () => {
    const wrapper = mountPasswordInput()

    await wrapper.find('input').setValue('nuevo')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['nuevo'])
  })

  it('forwards extra attributes like required/autocomplete to the input, not the wrapper', () => {
    const wrapper = mount(PasswordInput, {
      global: { plugins: [i18n] },
      props: { id: 'password', modelValue: '' },
      attrs: { required: true, autocomplete: 'current-password' },
    })

    const input = wrapper.find('input')
    expect(input.attributes('required')).toBeDefined()
    expect(input.attributes('autocomplete')).toBe('current-password')
    expect(wrapper.attributes('required')).toBeUndefined()
  })

  it('updates the aria-label/title to describe the action the click will perform', async () => {
    const wrapper = mountPasswordInput('secreto123')
    const button = wrapper.find('button')

    expect(button.attributes('aria-label')).toBe('Mostrar contraseña')

    await button.trigger('click')

    expect(button.attributes('aria-label')).toBe('Ocultar contraseña')
  })
})
