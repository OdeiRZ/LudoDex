<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useGamesStore } from '@/stores/games'

const games = useGamesStore()

const players = ref<number | null>(null)
const maxDuration = ref<number | null>(null)
// Exclusive on purpose, unlike the underlying data: a game's own
// is_cooperative/is_competitive flags are independent (a semi-cooperative
// game can be both), but as a filter "show me either" is a more useful
// question than "match both flags at once" when browsing.
const modeFilter = ref<'any' | 'cooperative' | 'competitive'>('any')
const campaignMode = ref<'any' | 'campaign' | 'arcade'>('any')

onMounted(() => {
  if (!games.loaded) {
    games.fetchAll()
  }
})

// With a single player there's no one to cooperate or compete with, so the
// mode filters stop being meaningful - hide them and drop whatever was
// selected instead of leaving a filter silently active behind a hidden
// checkbox.
const isSoloPlayer = computed(() => players.value === 1)

watch(isSoloPlayer, (solo) => {
  if (solo) {
    modeFilter.value = 'any'
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

    if (!isSoloPlayer.value) {
      if (modeFilter.value === 'cooperative' && !game.is_cooperative) return false
      if (modeFilter.value === 'competitive' && !game.is_competitive) return false
    }

    if (campaignMode.value === 'campaign' && !game.has_campaign) return false
    if (campaignMode.value === 'arcade' && game.has_campaign) return false

    return true
  })
})
</script>

<template>
  <div>
    <h1>¿A qué jugamos?</h1>

    <form class="filters card" @submit.prevent>
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

      <fieldset v-if="!isSoloPlayer">
        <legend>Modo</legend>
        <label><input v-model="modeFilter" type="radio" value="any" /> Cualquiera</label>
        <label><input v-model="modeFilter" type="radio" value="cooperative" /> Cooperativo</label>
        <label><input v-model="modeFilter" type="radio" value="competitive" /> Competitivo</label>
      </fieldset>

      <fieldset>
        <legend>Estructura</legend>
        <label><input v-model="campaignMode" type="radio" value="any" /> Cualquiera</label>
        <label><input v-model="campaignMode" type="radio" value="campaign" /> Campaña</label>
        <label><input v-model="campaignMode" type="radio" value="arcade" /> Arcade / partida suelta</label>
      </fieldset>

      <button type="submit" class="btn btn-primary">Buscar</button>
    </form>

    <p v-if="games.loading" class="loading-state">Cargando tu colección…</p>
    <p v-else-if="playable.length === 0" class="empty-state">
      No tienes juegos marcados como "Lo tengo" todavía.<br />
      <RouterLink :to="{ name: 'add-game' }">Añade uno</RouterLink>.
    </p>
    <p v-else-if="filtered.length === 0" class="empty-state">
      Ningún juego de tu colección encaja con estos filtros.
    </p>

    <ul v-else class="results">
      <li v-for="entry in filtered" :key="entry.id" class="card game-card">
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
          <span v-if="entry.game.is_cooperative" class="badge badge-primary">Cooperativo</span>
          <span v-if="entry.game.is_competitive" class="badge badge-primary">Competitivo</span>
          <span v-if="entry.game.has_campaign" class="badge badge-accent">Campaña</span>
        </p>
      </li>
    </ul>
  </div>
</template>

<style scoped>
h1 {
  margin-bottom: var(--space-4);
}

.filters {
  display: flex;
  gap: var(--space-6);
  flex-wrap: wrap;
  align-items: flex-start;
  margin-bottom: var(--space-6);
}

.filters > div {
  display: flex;
  flex-direction: column;
  max-width: 180px;
}

.results {
  list-style: none;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: var(--space-4);
}

.game-card {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.meta {
  display: flex;
  gap: var(--space-3);
  flex-wrap: wrap;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.tags {
  display: flex;
  gap: var(--space-2);
  flex-wrap: wrap;
}
</style>
