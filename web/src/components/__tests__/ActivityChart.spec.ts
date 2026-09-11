import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ActivityChart from '@/components/ActivityChart.vue'
import { i18n, setLocale } from '@/i18n'

// 12 months ending at a fixed "current" month (Jan 2026), oldest first,
// matching the shape the real API always returns - zero-filled except
// whichever months the test overrides.
function makeMonths(overrides: Record<string, number> = {}) {
  return Array.from({ length: 12 }, (_, i) => {
    const date = new Date(2026, 0, 1)
    date.setMonth(date.getMonth() - (11 - i))
    const month = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
    return { month, count: overrides[month] ?? 0 }
  })
}

function mountChart(months: { month: string; count: number }[]) {
  return mount(ActivityChart, { global: { plugins: [i18n] }, props: { months } })
}

describe('ActivityChart', () => {
  it('renders one bar per month, 12 total', () => {
    const wrapper = mountChart(makeMonths())

    expect(wrapper.findAll('.activity-bar-col')).toHaveLength(12)
  })

  it('shows the count for the current (last) month in the header', () => {
    const months = makeMonths({ '2026-01': 6 })
    const wrapper = mountChart(months)

    expect(wrapper.find('.activity-current').text()).toContain('6')
  })

  it('marks the highest month as the peak, not any zero month', () => {
    const months = makeMonths({ '2025-06': 3, '2025-09': 11 })
    const wrapper = mountChart(months)

    const peaks = wrapper.findAll('.activity-bar-col.is-peak')
    expect(peaks).toHaveLength(1)
    expect(peaks[0]!.find('.activity-tooltip').text()).toContain('11')
  })

  it('never marks a month peak when every month is zero', () => {
    const wrapper = mountChart(makeMonths())

    expect(wrapper.findAll('.activity-bar-col.is-peak')).toHaveLength(0)
  })

  it("formats each month's label using the active locale, not a hardcoded table", () => {
    setLocale('es')
    const wrapper = mountChart(makeMonths({ '2026-01': 2 }))

    expect(wrapper.findAll('.activity-month').at(-1)!.text()).toMatch(/ene/i)

    setLocale('en')
    const wrapperEn = mountChart(makeMonths({ '2026-01': 2 }))
    expect(wrapperEn.findAll('.activity-month').at(-1)!.text()).toMatch(/jan/i)

    setLocale('es')
  })

  it('pluralizes the tooltip count correctly for one play vs. several', () => {
    setLocale('es')
    const months = makeMonths({ '2025-11': 1, '2025-12': 4 })
    const wrapper = mountChart(months)

    const tooltips = wrapper.findAll('.activity-tooltip').map((t) => t.text())
    expect(tooltips.some((text) => /1 partida\b/.test(text))).toBe(true)
    expect(tooltips.some((text) => /4 partidas\b/.test(text))).toBe(true)
  })
})
