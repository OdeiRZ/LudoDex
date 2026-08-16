import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import GameCard from '@/components/GameCard.vue'

describe('GameCard', () => {
  it('renders the image when an image URL is given', () => {
    const wrapper = mount(GameCard, { props: { imageUrl: 'https://example.com/pic.jpg' } })

    expect(wrapper.find('.cover-image').exists()).toBe(true)
    expect(wrapper.find('.cover-image').attributes('src')).toBe('https://example.com/pic.jpg')
    expect(wrapper.find('.cover-fallback').exists()).toBe(false)
  })

  it('shows the fallback icon instead of a broken-image glyph when there is no image URL', () => {
    const wrapper = mount(GameCard, { props: { imageUrl: null } })

    expect(wrapper.find('.cover-image').exists()).toBe(false)
    expect(wrapper.find('.cover-fallback').exists()).toBe(true)
  })

  it('falls back to the icon when the image URL 404s', async () => {
    const wrapper = mount(GameCard, { props: { imageUrl: 'https://example.com/broken.jpg' } })

    await wrapper.find('.cover-image').trigger('error')

    expect(wrapper.find('.cover-image').exists()).toBe(false)
    expect(wrapper.find('.cover-fallback').exists()).toBe(true)
  })

  it('renders slot content inside the scrim', () => {
    const wrapper = mount(GameCard, {
      props: { imageUrl: null },
      slots: { default: '<h2>Root</h2>' },
    })

    expect(wrapper.find('.cover-scrim h2').text()).toBe('Root')
  })

  it('applies the compact class when the compact prop is set', () => {
    const wrapper = mount(GameCard, { props: { imageUrl: null, compact: true } })

    expect(wrapper.find('.game-cover').classes()).toContain('compact')
  })

  it('applies the expansion class only when isExpansion is set, not by default', () => {
    const base = mount(GameCard, { props: { imageUrl: null } })
    const expansion = mount(GameCard, { props: { imageUrl: null, isExpansion: true } })

    expect(base.find('.game-cover').classes()).not.toContain('expansion')
    expect(expansion.find('.game-cover').classes()).toContain('expansion')
  })
})
