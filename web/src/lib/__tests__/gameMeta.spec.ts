import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { formatRange } from '@/lib/gameMeta'
import GameMeta from '@/components/GameMeta.vue'
import { i18n } from '@/i18n'

describe('formatRange', () => {
  it('collapses equal ends and a missing end to a single number', () => {
    expect(formatRange(2, 2)).toBe('2')
    expect(formatRange(null, 5)).toBe('5')
    expect(formatRange(3, null)).toBe('3')
    expect(formatRange(1, 4)).toBe('1–4')
    expect(formatRange(null, null)).toBeNull()
    expect(formatRange(0, 0)).toBeNull()
  })
})

describe('GameMeta', () => {
  it('shows "2 jugadores", "30 min" and the year instead of "2–2 jugadores 30–30 min"', () => {
    const wrapper = mount(GameMeta, {
      props: { year: 2015, minPlayers: 2, maxPlayers: 2, minMinutes: 30, maxMinutes: 30 },
      global: { plugins: [i18n] },
    })

    expect(wrapper.text()).toContain('2 jugadores')
    expect(wrapper.text()).toContain('30 min')
    expect(wrapper.text()).not.toContain('–')
    expect(wrapper.text()).toContain('2015')
  })

  it('uses the singular for a solo game', () => {
    const wrapper = mount(GameMeta, {
      props: { minPlayers: 1, maxPlayers: 1 },
      global: { plugins: [i18n] },
    })

    expect(wrapper.find('.sr-only').text()).toBe('1 jugador')
  })

  it('renders nothing when there is no data', () => {
    const wrapper = mount(GameMeta, { props: {}, global: { plugins: [i18n] } })

    expect(wrapper.find('.meta').exists()).toBe(false)
  })
})
