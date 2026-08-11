<script setup lang="ts">
import { onMounted } from 'vue'
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
          $t('common.gamesCount', { count: games.collection.length })
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

    <ul class="games">
      <li v-for="entry in games.collection" :key="entry.id" class="card game-card">
        <div class="game-card-header">
          <GameThumbnail :image-url="entry.game.image_url" :size="56" />
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

.games {
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
