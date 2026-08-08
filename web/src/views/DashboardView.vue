<script setup lang="ts">
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useGamesStore } from '@/stores/games'

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
      <div class="actions">
        <RouterLink :to="{ name: 'import-bgg' }">Importar de BGG</RouterLink>
        <RouterLink :to="{ name: 'add-game' }" class="add-link">+ Añadir juego</RouterLink>
      </div>
    </div>

    <p v-if="games.loaded && games.collection.length === 0">
      Todavía no has añadido ningún juego.
      <RouterLink :to="{ name: 'add-game' }">Añade el primero</RouterLink>.
    </p>

    <ul class="games">
      <li v-for="entry in games.collection" :key="entry.id" class="game-card">
        <h2>{{ entry.game.name }}</h2>
        <p class="meta">
          <span v-if="entry.game.min_players || entry.game.max_players">
            {{ entry.game.min_players }}–{{ entry.game.max_players }} jugadores
          </span>
          <span v-if="entry.game.min_playtime_minutes || entry.game.max_playtime_minutes">
            {{ entry.game.min_playtime_minutes }}–{{ entry.game.max_playtime_minutes }} min
          </span>
          <span class="status">{{ entry.status === 'owned' ? 'Lo tengo' : 'Lo quiero' }}</span>
        </p>
        <p v-if="entry.game.mechanics.length" class="tags">
          {{ entry.game.mechanics.join(', ') }}
        </p>
        <button type="button" @click="onDelete(entry.id)">Quitar</button>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.actions {
  display: flex;
  gap: 1rem;
}

.games {
  list-style: none;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 1rem;
  margin-top: 1.5rem;
}

.game-card {
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 1rem;
}

.meta {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  font-size: 0.85rem;
  color: var(--color-text);
  opacity: 0.8;
}

.status {
  font-weight: bold;
}

.tags {
  font-size: 0.8rem;
  opacity: 0.7;
}
</style>
