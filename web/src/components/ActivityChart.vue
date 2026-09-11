<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { getLocale } from '@/i18n'

const props = defineProps<{
  months: { month: string; count: number }[]
}>()

const { t } = useI18n()

// Native Intl over a hand-rolled month-name table - already the pattern
// this project uses elsewhere (Intl.ListFormat for friend name lists) -
// so "Ene"/"Jan" etc. come out right for whichever locale is active for
// free, no separate translation table to keep in sync.
const monthFormatter = computed(
  () => new Intl.DateTimeFormat(getLocale(), { month: 'short' }),
)

function formatMonth(month: string): string {
  // month is "YYYY-MM" - the day is fixed at 1 and never shown, only fed
  // to the formatter so it has a real Date to work from.
  const [year, monthNumber] = month.split('-').map(Number)
  return monthFormatter.value.format(new Date(year, monthNumber - 1, 1))
}

const maxCount = computed(() => Math.max(1, ...props.months.map((m) => m.count)))

const bars = computed(() =>
  props.months.map((entry) => ({
    ...entry,
    label: formatMonth(entry.month),
    // Never fully flat even at 0 - a sliver still reads as "this month
    // exists, it's just empty", where 0% height would look like a
    // missing/broken bar instead of a real zero.
    heightPercent: Math.max(4, (entry.count / maxCount.value) * 100),
    isPeak: entry.count === maxCount.value && entry.count > 0,
  })),
)

const currentMonthCount = computed(() => props.months.at(-1)?.count ?? 0)
</script>

<template>
  <div class="activity-card">
    <div class="activity-head">
      <p class="activity-title">{{ t('plays.activityTitle') }}</p>
      <span class="activity-current">{{
        t('plays.activityCurrentMonth', { count: currentMonthCount })
      }}</span>
    </div>
    <div class="activity-chart">
      <div v-for="bar in bars" :key="bar.month" class="activity-bar-col" :class="{ 'is-peak': bar.isPeak }">
        <div class="activity-bar" :style="{ height: `${bar.heightPercent}%` }">
          <span class="activity-tooltip">{{
            t('plays.activityTooltip', { month: bar.label, count: bar.count }, bar.count)
          }}</span>
        </div>
        <span class="activity-month">{{ bar.label }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.activity-card {
  background: var(--color-surface);
  border-radius: var(--radius);
  box-shadow: var(--shadow-card);
  padding: var(--space-4);
  margin-bottom: var(--space-4);
}

.activity-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-3);
  margin-bottom: var(--space-4);
}

.activity-title {
  font-size: 0.9rem;
  color: var(--color-text-muted);
  margin: 0;
}

.activity-current {
  font-size: 0.82rem;
  color: var(--color-text-muted);
}

.activity-chart {
  display: flex;
  align-items: flex-end;
  gap: var(--space-2);
  height: 96px;
}

.activity-bar-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  height: 100%;
  justify-content: flex-end;
  gap: 0.4rem;
}

.activity-bar {
  width: 100%;
  max-width: 28px;
  border-radius: 4px 4px 0 0;
  background: color-mix(in srgb, var(--color-primary) 35%, transparent);
  transition:
    background-color 0.15s ease,
    transform 0.15s ease;
  transform-origin: bottom;
  position: relative;
}

.activity-bar-col:hover .activity-bar {
  background: var(--color-primary);
  transform: scaleY(1.03);
}

.activity-bar-col.is-peak .activity-bar {
  background: var(--color-primary);
}

.activity-tooltip {
  position: absolute;
  bottom: 100%;
  left: 50%;
  transform: translate(-50%, -6px) scale(0.9);
  background: var(--color-heading);
  color: var(--color-background);
  font-size: 0.72rem;
  font-weight: 600;
  padding: 0.2rem 0.5rem;
  border-radius: 4px;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  transition:
    opacity 0.12s ease,
    transform 0.12s ease;
}

.activity-bar-col:hover .activity-tooltip {
  opacity: 1;
  transform: translate(-50%, -10px) scale(1);
}

.activity-month {
  font-size: 0.7rem;
  color: var(--color-text-muted);
}

.activity-bar-col.is-peak .activity-month {
  color: var(--color-heading);
  font-weight: 600;
}

@media (prefers-reduced-motion: reduce) {
  .activity-bar,
  .activity-tooltip {
    transition: none;
  }
}
</style>
