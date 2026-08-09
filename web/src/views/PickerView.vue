<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useGamesStore } from '@/stores/games'
import GameThumbnail from '@/components/GameThumbnail.vue'

const games = useGamesStore()

// Starts at 2 rather than empty: the placeholder text has no room to
// display fully next to the "Solo" button at this width, and most groups
// browsing this page are more than one person anyway.
const DEFAULT_PLAYERS = 2
const players = ref<number | null>(DEFAULT_PLAYERS)
const maxDuration = ref<number | null>(null)
// Exclusive on purpose, unlike the underlying data: a game's own
// is_cooperative/is_competitive flags are independent (a semi-cooperative
// game can be both), but as a filter "show me either" is a more useful
// question than "match both flags at once" when browsing.
const modeFilter = ref<'any' | 'cooperative' | 'competitive'>('any')
const campaignMode = ref<'any' | 'campaign' | 'arcade'>('any')
const categoryFilter = ref('')

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

// Shortcut for the common "just me" case: same effect as typing 1 into the
// Jugadores field (isSoloPlayer above reacts to either), just one tap
// instead of opening a number keyboard on mobile.
function toggleSolo() {
  players.value = isSoloPlayer.value ? DEFAULT_PLAYERS : 1
}

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

// Built from the playable collection itself, not the full catalog
// (games.categoryOptions): a category only some wishlist/unowned game has
// would otherwise show up as a choice that can never return a result here.
const availableCategories = computed(() =>
  [...new Set(playable.value.flatMap(({ game }) => game.categories))].sort((a, b) =>
    a.localeCompare(b),
  ),
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

    if (categoryFilter.value !== '' && !game.categories.includes(categoryFilter.value)) {
      return false
    }

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
        <div class="players-row">
          <input
            id="players"
            v-model.number="players"
            type="number"
            min="1"
            placeholder="Nº"
          />
          <button
            type="button"
            class="btn"
            :class="{ 'btn-primary': isSoloPlayer }"
            :aria-pressed="isSoloPlayer"
            @click="toggleSolo"
          >
            Solo
          </button>
        </div>
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

      <div v-if="availableCategories.length">
        <label for="category">Género</label>
        <select id="category" v-model="categoryFilter">
          <option value="">Cualquiera</option>
          <option v-for="category in availableCategories" :key="category" :value="category">
            {{ category }}
          </option>
        </select>
      </div>

      <div>
        <span class="filter-label-spacer" aria-hidden="true">&nbsp;</span>
        <button type="submit" class="btn btn-primary">Buscar</button>
      </div>
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
        <div class="game-card-header">
          <GameThumbnail :image-url="entry.game.image_url" :size="56" />
          <h2>{{ entry.game.name }}</h2>
          <RouterLink
            :to="{ name: 'edit-game', params: { id: entry.id }, query: { from: 'picker' } }"
            class="edit-icon-button"
            aria-label="Editar juego"
            title="Editar juego"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"
              />
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9c.36-.62.29-1.4-.19-1.99l-.06-.06A2 2 0 1 1 7.18 4.12l.06.06A1.65 1.65 0 0 0 9 4.51c.62-.24 1-.85 1-1.51V3a2 2 0 1 1 4 0v.09c0 .66.38 1.27 1 1.51.62.24 1.33.13 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.24.62.85 1 1.51 1H21a2 2 0 1 1 0 4h-.09c-.66 0-1.27.38-1.51 1Z"
              />
            </svg>
          </RouterLink>
        </div>
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

.players-row {
  display: flex;
  gap: var(--space-2);
}

.players-row input {
  min-width: 0;
}

.filter-label-spacer {
  display: block;
  font-size: 0.875rem;
  margin-bottom: var(--space-1);
}

.players-row .btn {
  flex-shrink: 0;
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

.game-card-header {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.game-card-header h2 {
  overflow-wrap: anywhere;
  flex: 1;
  min-width: 0;
}

.edit-icon-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text-muted);
}

.edit-icon-button:hover {
  background: var(--color-surface-hover);
  color: var(--color-text);
}

.edit-icon-button svg {
  width: 16px;
  height: 16px;
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
