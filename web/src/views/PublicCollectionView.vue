<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { isAxiosError } from 'axios'
import { apiClient } from '@/lib/api'
import { getLocale } from '@/i18n'
import GameCard from '@/components/GameCard.vue'
import GameMeta from '@/components/GameMeta.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import type { Game } from '@/stores/games'

// Vista pública de solo lectura de una colección compartida por enlace: no necesita
// sesión y no usa ningún store de la app (ni los juegos de quien la mira ni nada
// suyo). El servidor solo devuelve el nombre del dueño, los juegos que tiene (no la
// lista de deseos) y la caducidad.
interface PublicCollection {
  owner_name: string
  expires_at: string | null
  games: Game[]
}

const route = useRoute()
const collection = ref<PublicCollection | null>(null)
const loading = ref(true)
// Inexistente, revocado y caducado se ven igual (el servidor responde 404 a los tres).
const notFound = ref(false)
const failed = ref(false)

const search = ref('')
const players = ref<number | null>(null)
const showExpansions = ref(false)

onMounted(async () => {
  try {
    const { data } = await apiClient.get(`/public/collections/${route.params.token}`)
    collection.value = data.data
  } catch (error) {
    if (isAxiosError(error) && error.response?.status === 404) notFound.value = true
    else failed.value = true
  } finally {
    loading.value = false
  }
})

const baseCount = computed(
  () => collection.value?.games.filter((g) => g.base_game_id === null).length ?? 0,
)

const visible = computed(() => {
  const needle = search.value.trim().toLowerCase()
  return (collection.value?.games ?? []).filter((game) => {
    if (!showExpansions.value && game.base_game_id !== null) return false
    if (needle && !game.name.toLowerCase().includes(needle)) return false
    if (players.value !== null) {
      const min = game.min_players ?? 1
      const max = game.max_players ?? min
      if (players.value < min || players.value > max) return false
    }
    return true
  })
})

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString(getLocale(), {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}
</script>

<template>
  <div class="public-collection">
    <LoadingSpinner v-if="loading" />

    <div v-else-if="notFound" class="state" data-testid="public-not-found">
      <h1>{{ $t('publicCollection.notFoundTitle') }}</h1>
      <p>{{ $t('publicCollection.notFoundMessage') }}</p>
    </div>

    <div v-else-if="failed || !collection" class="state" role="alert">
      <h1>{{ $t('publicCollection.errorTitle') }}</h1>
      <p>{{ $t('publicCollection.errorMessage') }}</p>
    </div>

    <template v-else>
      <header>
        <h1>{{ $t('publicCollection.title', { name: collection.owner_name }) }}</h1>
        <p class="meta">
          {{ $t('publicCollection.count', { count: baseCount }, baseCount) }}
          <template v-if="collection.expires_at">
            · {{ $t('publicCollection.expiresOn', { date: formatDate(collection.expires_at) }) }}
          </template>
        </p>
        <p class="meta">{{ $t('publicCollection.readOnly') }}</p>
      </header>

      <div class="filters">
        <input
          v-model="search"
          type="search"
          :placeholder="$t('publicCollection.search')"
          :aria-label="$t('publicCollection.search')"
        />
        <label>
          {{ $t('publicCollection.players') }}
          <input v-model.number="players" type="number" min="1" max="99" class="players" />
        </label>
        <label class="check">
          <input v-model="showExpansions" type="checkbox" />
          {{ $t('publicCollection.showExpansions') }}
        </label>
      </div>

      <p v-if="visible.length === 0" class="state">{{ $t('publicCollection.noMatches') }}</p>

      <ul v-else class="games">
        <li v-for="game in visible" :key="game.id">
          <GameCard :image-url="game.image_url" :is-expansion="game.base_game_id !== null">
            <h3>{{ game.name }}</h3>
            <GameMeta
              class="game-meta"
              :year="game.year_published"
              :min-players="game.min_players"
              :max-players="game.max_players"
              :min-minutes="game.min_playtime_minutes"
              :max-minutes="game.max_playtime_minutes"
            />
          </GameCard>
        </li>
      </ul>
    </template>
  </div>
</template>

<style scoped>
.public-collection {
  max-width: 1000px;
  margin: var(--space-6) auto 0;
}

header {
  margin-bottom: var(--space-4);
}

h1 {
  margin-bottom: var(--space-1);
}

.meta {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.state {
  max-width: 420px;
  margin: var(--space-6) auto 0;
  text-align: center;
  color: var(--color-text-muted);
}

.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-4);
}

.filters input[type='search'] {
  flex: 1;
  min-width: 180px;
}

.players {
  width: 4.5rem;
}

.check {
  display: flex;
  align-items: center;
  gap: var(--space-1);
}

.games {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: var(--space-3);
}

h3 {
  font-size: 1rem;
  margin: 0;
}

.game-meta {
  margin-top: var(--space-1);
}
</style>
