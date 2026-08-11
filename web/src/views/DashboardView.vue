<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useGamesStore } from '@/stores/games'
import GameThumbnail from '@/components/GameThumbnail.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const auth = useAuthStore()
const games = useGamesStore()

onMounted(() => {
  if (!auth.user) {
    auth.fetchCurrentUser()
  }

  if (!games.loaded) {
    games.fetchAll()
  }
})

// Client-side against the collection already loaded in the store, same as
// the picker's filters - a real collection can run into the hundreds of
// games (see the BGG CSV import), and finding one by scrolling stopped
// being practical well before that.
const search = ref('')

const filtered = computed(() => {
  const query = search.value.trim().toLowerCase()

  if (!query) {
    return games.collection
  }

  return games.collection.filter((entry) => entry.game.name.toLowerCase().includes(query))
})

// Persisted the same way as the theme (localStorage, read once on load) -
// a plain per-view preference, not something other components need, so it
// stays local here rather than becoming a shared composable like useTheme.
const DENSITY_STORAGE_KEY = 'ludodex-collection-density'
type Density = 'comfortable' | 'compact'

function initialDensity(): Density {
  return localStorage.getItem(DENSITY_STORAGE_KEY) === 'compact' ? 'compact' : 'comfortable'
}

const density = ref<Density>(initialDensity())

function toggleDensity() {
  density.value = density.value === 'compact' ? 'comfortable' : 'compact'
  localStorage.setItem(DENSITY_STORAGE_KEY, density.value)
}

async function onDelete(userGameId: string) {
  await games.deleteGame(userGameId)
}
</script>

<template>
  <div>
    <div class="header">
      <div class="title-row">
        <h1>{{ $t('dashboard.title') }}</h1>
        <span v-if="games.loaded" class="count">{{
          $t('common.gamesCount', { count: filtered.length })
        }}</span>
      </div>
      <RouterLink :to="{ name: 'add-game' }" class="btn btn-primary">{{
        $t('dashboard.addGame')
      }}</RouterLink>
    </div>

    <p v-if="games.loading" class="loading-state">
      <LoadingSpinner :size="28" />
      {{ $t('common.loadingCollection') }}
    </p>

    <p v-else-if="games.loaded && games.collection.length === 0" class="empty-state">
      {{ $t('dashboard.empty') }}<br />
      <RouterLink :to="{ name: 'add-game' }">{{ $t('dashboard.addFirst') }}</RouterLink>.
    </p>

    <template v-else-if="games.loaded">
      <div class="search-row">
        <input
          v-model="search"
          type="search"
          :aria-label="$t('dashboard.searchLabel')"
          :placeholder="$t('dashboard.searchPlaceholder')"
        />
        <button
          type="button"
          class="density-toggle"
          :aria-label="density === 'compact' ? $t('dashboard.densityToComfortable') : $t('dashboard.densityToCompact')"
          :title="density === 'compact' ? $t('dashboard.densityToComfortable') : $t('dashboard.densityToCompact')"
          @click="toggleDensity"
        >
          <!-- Icon shows the mode a click leads to, not the current one -
          same convention as ThemeToggle. -->
          <svg v-if="density === 'compact'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <rect x="3" y="4" width="18" height="6" rx="1" />
            <rect x="3" y="14" width="18" height="6" rx="1" />
          </svg>
          <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <line x1="3" y1="5" x2="21" y2="5" />
            <line x1="3" y1="10" x2="21" y2="10" />
            <line x1="3" y1="15" x2="21" y2="15" />
            <line x1="3" y1="20" x2="21" y2="20" />
          </svg>
        </button>
      </div>

      <p v-if="filtered.length === 0" class="empty-state">
        {{ $t('dashboard.noMatches') }}
      </p>
    </template>

    <ul class="games" :class="{ compact: density === 'compact' }">
      <li v-for="entry in filtered" :key="entry.id" class="card game-card">
        <div class="game-card-header">
          <GameThumbnail :image-url="entry.game.image_url" :size="density === 'compact' ? 36 : 56" />
          <div class="game-card-title">
            <h2>{{ entry.game.name }}</h2>
            <span
              class="badge"
              :class="entry.status === 'owned' ? 'badge-primary' : 'badge-accent'"
            >
              {{ entry.status === 'owned' ? $t('dashboard.owned') : $t('dashboard.wishlist') }}
            </span>
          </div>
        </div>
        <p class="meta">
          <span v-if="entry.game.min_players || entry.game.max_players">
            {{ $t('dashboard.players', { min: entry.game.min_players, max: entry.game.max_players }) }}
          </span>
          <span v-if="entry.game.min_playtime_minutes || entry.game.max_playtime_minutes">
            {{
              $t('dashboard.duration', {
                min: entry.game.min_playtime_minutes,
                max: entry.game.max_playtime_minutes,
              })
            }}
          </span>
        </p>
        <p v-if="entry.game.mechanics.length" class="tags">
          {{ entry.game.mechanics.join(', ') }}
        </p>
        <div class="card-actions">
          <RouterLink
            :to="{ name: 'edit-game', params: { id: entry.id }, query: { from: 'dashboard' } }"
            class="btn"
          >
            {{ $t('dashboard.edit') }}
          </RouterLink>
          <button type="button" class="btn btn-danger" @click="onDelete(entry.id)">
            {{ $t('dashboard.remove') }}
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--space-6);
}

.title-row {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
}

.count {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.search-row {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-4);
}

.search-row input {
  max-width: 320px;
}

.density-toggle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  padding: 0;
  flex-shrink: 0;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text);
}

.density-toggle:hover {
  background: var(--color-surface-hover);
}

.density-toggle svg {
  width: 18px;
  height: 18px;
}

.games {
  list-style: none;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: var(--space-4);
}

.games.compact {
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: var(--space-2);
}

.games.compact .game-card {
  padding: var(--space-2);
  gap: var(--space-1);
}

.games.compact .tags {
  display: none;
}

.game-card {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.game-card-header {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
}

.game-card-title {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  align-items: flex-start;
  min-width: 0;
}

.game-card-title h2 {
  overflow-wrap: anywhere;
}

.meta {
  display: flex;
  gap: var(--space-3);
  flex-wrap: wrap;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.tags {
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.card-actions {
  display: flex;
  gap: var(--space-2);
  margin-top: var(--space-2);
}
</style>
