<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useGamesStore } from '@/stores/games'
import GameThumbnail from '@/components/GameThumbnail.vue'

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
      <h1>Tu colección</h1>
      <RouterLink :to="{ name: 'add-game' }" class="btn btn-primary">+ Añadir juego</RouterLink>
    </div>

    <p v-if="games.loading" class="loading-state">Cargando tu colección…</p>

    <p v-else-if="games.loaded && games.collection.length === 0" class="empty-state">
      Todavía no has añadido ningún juego.<br />
      <RouterLink :to="{ name: 'add-game' }">Añade el primero</RouterLink>.
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
              {{ entry.status === 'owned' ? 'Lo tengo' : 'Lo quiero' }}
            </span>
          </div>
        </div>
        <p class="meta">
          <span v-if="entry.game.min_players || entry.game.max_players">
            {{ entry.game.min_players }}–{{ entry.game.max_players }} jugadores
          </span>
          <span v-if="entry.game.min_playtime_minutes || entry.game.max_playtime_minutes">
            {{ entry.game.min_playtime_minutes }}–{{ entry.game.max_playtime_minutes }} min
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
            Editar
          </RouterLink>
          <button type="button" class="btn btn-danger" @click="onDelete(entry.id)">Quitar</button>
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
