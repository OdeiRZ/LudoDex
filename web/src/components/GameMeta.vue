<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatRange } from '@/lib/gameMeta'

// Datos de un juego sobre el velo de su portada: jugadores y duración en
// pastillas con icono (lo que decide a qué jugar, delante) y el año al final,
// más apagado. No pinta nada si no hay ningún dato.
const props = defineProps<{
  year?: number | null
  minPlayers?: number | null
  maxPlayers?: number | null
  minMinutes?: number | null
  maxMinutes?: number | null
}>()

const { t } = useI18n()

const players = computed(() => formatRange(props.minPlayers, props.maxPlayers))
const playersLabel = computed(() =>
  players.value === null
    ? ''
    : t('dashboard.players', { range: players.value }, props.maxPlayers || props.minPlayers || 0),
)
const duration = computed(() => formatRange(props.minMinutes, props.maxMinutes))
const hasAny = computed(() => !!props.year || players.value !== null || duration.value !== null)
</script>

<template>
  <p v-if="hasAny" class="meta">
    <span v-if="players !== null" class="meta-chip">
      <svg class="meta-icon" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
        <circle cx="9" cy="7" r="4" />
        <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
      </svg>
      <span aria-hidden="true">{{ players }}</span>
      <span class="sr-only">{{ playersLabel }}</span>
    </span>
    <span v-if="duration !== null" class="meta-chip">
      <svg class="meta-icon" viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
      </svg>
      {{ t('dashboard.duration', { range: duration }) }}
    </span>
    <span v-if="year" class="meta-chip meta-chip-quiet">{{ year }}</span>
  </p>
</template>

<style scoped>
/* Siempre sobre el velo oscuro de la portada (ver GameCard), así que los
   colores son fijos y no del tema, igual que el resto del texto del velo. */
.meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
  margin: 0;
}

.meta-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.12rem 0.5rem;
  border-radius: var(--radius-pill);
  background: rgba(255, 255, 255, 0.16);
  color: rgba(255, 255, 255, 0.92);
  font-size: 0.75rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.meta-chip-quiet {
  background: transparent;
  color: rgba(255, 255, 255, 0.7);
  font-weight: 500;
  padding-inline: 0.25rem;
}

.meta-icon {
  width: 0.85rem;
  height: 0.85rem;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
  flex-shrink: 0;
}
</style>
