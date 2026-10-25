import type { Play } from '@/stores/plays'

type Translate = (key: string, named: Record<string, unknown>) => string

// "45 min", "6 h", "3 h 30 min" - las mismas claves que ya usa el resumen de
// arriba ("Tiempo total"), para que una partida y el total se lean igual.
export function formatDuration(minutes: number, t: Translate): string {
  const hours = Math.floor(minutes / 60)
  const rest = Math.round(minutes % 60)
  if (hours === 0) return t('plays.duration', { minutes: rest })
  return rest === 0
    ? t('plays.statsHoursOnly', { hours })
    : t('plays.statsHoursMinutes', { hours, minutes: rest })
}

// played_at llega como "YYYY-MM-DD" (o con hora detrás): se lee como fecha
// local, no como UTC, para que una partida del día 1 no salga el 31.
export function playDate(playedAt: string): Date {
  const [year, month, day] = playedAt.slice(0, 10).split('-').map(Number)
  return new Date(year, month - 1, day)
}

export interface PlayMonthGroup {
  key: string
  plays: Play[]
  /** Partidas del mes contando las repeticiones (×N), como el resumen. */
  count: number
  /** Minutos de las partidas con duración conocida, también por repetición. */
  minutes: number
  /**
   * false solo para el último grupo mientras queden páginas por cargar: ese
   * mes puede seguir en la página siguiente y sus totales estarían cortos.
   */
  complete: boolean
}

// La lista ya viene ordenada de más reciente a más antigua; se agrupa por
// mes sin reordenar.
export function groupPlaysByMonth(plays: Play[], hasMorePages: boolean): PlayMonthGroup[] {
  const groups: PlayMonthGroup[] = []
  for (const play of plays) {
    const key = play.played_at.slice(0, 7)
    let group = groups.at(-1)
    if (!group || group.key !== key) {
      group = { key, plays: [], count: 0, minutes: 0, complete: true }
      groups.push(group)
    }
    group.plays.push(play)
    group.count += play.quantity
    if (play.duration_minutes) group.minutes += play.duration_minutes * play.quantity
  }
  const last = groups.at(-1)
  if (last && hasMorePages) last.complete = false
  return groups
}
