import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PoweredByBgg from '@/components/PoweredByBgg.vue'
import { useTheme } from '@/composables/useTheme'

describe('PoweredByBgg', () => {
  it('links back to BoardGameGeek in a new tab, as required by the XML API terms of use', () => {
    const wrapper = mount(PoweredByBgg)
    const link = wrapper.find('a')

    expect(link.attributes('href')).toBe('https://boardgamegeek.com/')
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toContain('noopener')
  })

  it('shows the dark-text logo in light theme', () => {
    useTheme().theme.value = 'light'
    const wrapper = mount(PoweredByBgg)

    const src = wrapper.find('img').attributes('src')
    expect(src).toContain('powered-by-bgg-rgb')
    expect(src).not.toContain('reversed')
  })

  // The "reversed" (white text) variant BGG provides only reads on a dark
  // background - it has to track the app's own theme toggle rather than
  // picking one variant permanently, or it goes illegible in one mode.
  it('swaps to the white-text reversed logo in dark theme', () => {
    useTheme().theme.value = 'dark'
    const wrapper = mount(PoweredByBgg)

    expect(wrapper.find('img').attributes('src')).toContain('powered-by-bgg-reversed-rgb')
  })
})
