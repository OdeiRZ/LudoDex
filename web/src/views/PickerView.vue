<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useGamesStore } from '@/stores/games'

const games = useGamesStore()

const players = ref<number | null>(null)
const maxDuration = ref<number | null>(null)
const requireCooperative = ref(false)
const requireCompetitive = ref(false)
const requireCampaign = ref(false)

onMounted(() => {
  if (!games.loaded) {
    games.fetchAll()
  }
})

// Only games you actually own, and never a standalone expansion (it isn't
// playable without its base game - see the README's expansions note).
const playable = computed(() =>
  games.collection.filter((entry) => entry.status === 'owned' && entry.game.base_game_id === null),
)

// v-model.number leaves the ref as an empty string (not null) when the
// input is cleared - "" < 3 coerces to 0 < 3 in JS, so without this guard
// clearing the field would silently filter out every game with any minimum
// player count instead of removing the filter.
function asFilterNumber(value: number | null): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

const filtered = computed(() => {
  const minPlayersFilter = asFilterNumber(players.value)
  const maxDurationFilter = asFilterNumber(maxDuration.value)

  return playable.value.filter(({ game }) => {
    if (minPlayersFilter !== null) {
      if (game.min_players !== null && minPlayersFilter < game.min_players) return false
      if (game.max_players !== null && minPlayersFilter > game.max_players) return false
    }

    if (
      maxDurationFilter !== null &&
      game.max_playtime_minutes !== null &&
      game.max_playtime_minutes > maxDurationFilter
    ) {
      return false
    }

    if (requireCooperative.value && !game.is_cooperative) return false
    if (requireCompetitive.value && !game.is_competitive) return false
    if (requireCampaign.value && !game.has_campaign) return false

    return true
  })
})
</script>

<template>
  <div>
    <h1>¿A qué jugamos?</h1>

    <form class="filters" @submit.prevent>
      <div>
        <label for="players">Jugadores</label>
        <input
          id="players"
          v-model.number="players"
          type="number"
          min="1"
          placeholder="Cuántos sois"
        />
      </div>

      <div>
        <label for="duration">Minutos disponibles</label>
        <input
          id="duration"
          v-model.number="maxDuration"
          type="number"
          min="1"
          placeholder="Tiempo que tenéis"
        />
      </div>

      <fieldset>
        <legend>Modo</legend>
        <label><input v-model="requireCooperative" type="checkbox" /> Cooperativo</label>
        <label><input v-model="requireCompetitive" type="checkbox" /> Competitivo</label>
        <label><input v-model="requireCampaign" type="checkbox" /> Campaña</label>
      </fieldset>
    </form>

    <p v-if="playable.length === 0">
      No tienes juegos marcados como "Lo tengo" todavía.
      <RouterLink :to="{ name: 'add-game' }">Añade uno</RouterLink>.
    </p>
    <p v-else-if="filtered.length === 0">Ningún juego de tu colección encaja con estos filtros.</p>

    <ul v-else class="results">
      <li v-for="entry in filtered" :key="entry.id" class="game-card">
        <h2>{{ entry.game.name }}</h2>
        <p class="meta">
          <span v-if="entry.game.min_players || entry.game.max_players">
            {{ entry.game.min_players }}–{{ entry.game.max_players }} jugadores
          </span>
          <span v-if="entry.game.min_playtime_minutes || entry.game.max_playtime_minutes">
            {{ entry.game.min_playtime_minutes }}–{{ entry.game.max_playtime_minutes }} min
          </span>
        </p>
        <p class="tags">
          <span v-if="entry.game.is_cooperative">Cooperativo</span>
          <span v-if="entry.game.is_competitive">Competitivo</span>
          <span v-if="entry.game.has_campaign">Campaña</span>
        </p>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.filters {
  display: flex;
  gap: 1.5rem;
  flex-wrap: wrap;
  align-items: flex-start;
  margin: 1rem 0 1.5rem;
}

.filters > div {
  display: flex;
  flex-direction: column;
}

fieldset {
  display: flex;
  gap: 1rem;
  border: 1px solid var(--color-border);
}

.results {
  list-style: none;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 1rem;
}

.game-card {
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 1rem;
}

.meta,
.tags {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  font-size: 0.85rem;
  opacity: 0.8;
}
</style>
