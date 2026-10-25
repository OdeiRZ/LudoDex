import { describe, it, expect } from 'vitest'
import { formatDuration, groupPlaysByMonth, playDate } from '@/lib/playFormat'
import type { Play } from '@/stores/plays'

const t = (key: string, named: Record<string, unknown>) =>
  ({
    'plays.duration': `${named.minutes} min`,
    'plays.statsHoursOnly': `${named.hours} h`,
    'plays.statsHoursMinutes': `${named.hours} h ${named.minutes} min`,
  })[key] ?? key

function play(id: number, playedAt: string, minutes: number | null, quantity = 1): Play {
  return { id, played_at: playedAt, duration_minutes: minutes, quantity } as unknown as Play
}

describe('formatDuration', () => {
  it('reads 360 min as "6 h" and 210 as "3 h 30 min"', () => {
    expect(formatDuration(45, t)).toBe('45 min')
    expect(formatDuration(360, t)).toBe('6 h')
    expect(formatDuration(210, t)).toBe('3 h 30 min')
  })
})

describe('playDate', () => {
  it('reads the date as local, so the 1st never becomes the 31st', () => {
    const date = playDate('2026-09-01')
    expect([date.getFullYear(), date.getMonth(), date.getDate()]).toEqual([2026, 8, 1])
  })
})

describe('groupPlaysByMonth', () => {
  it('groups by month, counting repeats like the summary does', () => {
    const groups = groupPlaysByMonth(
      [
        play(1, '2026-09-01', 360),
        play(2, '2026-08-21', 210),
        play(3, '2026-08-09', 60, 2),
        play(4, '2026-08-02', null),
      ],
      false,
    )

    expect(groups.map((g) => [g.key, g.count, g.minutes, g.complete])).toEqual([
      ['2026-09', 1, 360, true],
      ['2026-08', 4, 330, true],
    ])
  })

  it('marks the last month incomplete while more pages remain', () => {
    const groups = groupPlaysByMonth([play(1, '2026-09-01', 60), play(2, '2026-08-21', 60)], true)

    expect(groups.map((g) => g.complete)).toEqual([true, false])
  })
})
