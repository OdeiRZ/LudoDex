import { describe, it, expect, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ScrollToTopButton from '@/components/ScrollToTopButton.vue'
import { i18n } from '@/i18n'

function setScrollY(value: number) {
  Object.defineProperty(window, 'scrollY', { value, writable: true, configurable: true })
}

describe('ScrollToTopButton', () => {
  afterEach(() => {
    setScrollY(0)
  })

  it('stays hidden until the page is scrolled down past the threshold', () => {
    setScrollY(0)
    const wrapper = mount(ScrollToTopButton, { global: { plugins: [i18n] } })

    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('appears once scrolled down, and scrolls back to the top on click', async () => {
    const scrollToSpy = vi.fn()
    window.scrollTo = scrollToSpy

    setScrollY(500)
    const wrapper = mount(ScrollToTopButton, { global: { plugins: [i18n] } })
    window.dispatchEvent(new Event('scroll'))
    await wrapper.vm.$nextTick()

    const button = wrapper.find('button')
    expect(button.exists()).toBe(true)

    await button.trigger('click')

    expect(scrollToSpy).toHaveBeenCalledWith(expect.objectContaining({ top: 0 }))
  })
})
