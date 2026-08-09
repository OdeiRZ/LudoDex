<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { isAxiosError } from 'axios'
import { useGamesStore } from '@/stores/games'
import GameForm, { type GameFormData } from '@/components/GameForm.vue'
import { useSlowRequestHint } from '@/composables/useSlowRequestHint'

const props = defineProps<{ id: string }>()

const router = useRouter()
const games = useGamesStore()
const { isSlow, wrap } = useSlowRequestHint()

const form = reactive<GameFormData>({
  name: '',
  image_url: null,
  min_players: null,
  max_players: null,
  min_playtime_minutes: null,
  max_playtime_minutes: null,
  weight: null,
  is_cooperative: false,
  is_competitive: false,
  has_campaign: false,
  mechanics: [],
  categories: [],
  status: 'owned',
})

const errors = ref<Record<string, string[]>>({})
const submitting = ref(false)

const entry = computed(() => games.collection.find((item) => item.id === props.id))

function fillFormFromEntry() {
  if (!entry.value) {
    return
  }

  const { game, status } = entry.value

  form.name = game.name
  form.image_url = game.image_url
  form.min_players = game.min_players
  form.max_players = game.max_players
  form.min_playtime_minutes = game.min_playtime_minutes
  form.max_playtime_minutes = game.max_playtime_minutes
  form.weight = game.weight
  form.is_cooperative = game.is_cooperative
  form.is_competitive = game.is_competitive
  form.has_campaign = game.has_campaign
  form.mechanics = [...game.mechanics]
  form.categories = [...game.categories]
  form.status = status
}

onMounted(async () => {
  if (!games.loaded) {
    await games.fetchAll()
  }

  fillFormFromEntry()
})

// Covers navigating here directly (already loaded collection, entry ready
// on the first tick) as well as the fetchAll-on-mount case above.
watch(entry, fillFormFromEntry)

async function onSubmit() {
  errors.value = {}
  submitting.value = true

  try {
    await wrap(games.updateGame(props.id, form))
    router.push({ name: 'dashboard' })
  } catch (err) {
    if (isAxiosError(err) && err.response?.status === 422) {
      const fieldErrors: Record<string, string[]> = err.response.data.errors
      errors.value = { general: Object.values(fieldErrors).flat() }
    } else {
      errors.value = { general: ['No se ha podido guardar el juego. Revisa los datos.'] }
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="edit-game">
    <h1>Editar juego</h1>

    <p v-if="games.loading" class="loading-state">Cargando…</p>

    <p v-else-if="!entry" class="empty-state">
      No se ha encontrado ese juego en tu colección.
    </p>

    <form v-else @submit.prevent="onSubmit">
      <GameForm v-model="form" :submitting="submitting" submit-label="Guardar cambios" :errors="errors" />

      <p v-if="isSlow" class="slow-request-hint">
        Puede tardar unos segundos si el servidor estaba inactivo.
      </p>
    </form>
  </div>
</template>

<style scoped>
.edit-game {
  max-width: 520px;
  margin: 0 auto;
}

h1 {
  margin-bottom: var(--space-4);
}
</style>
