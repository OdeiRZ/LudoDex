<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { isAxiosError } from 'axios'
import { useGamesStore } from '@/stores/games'
import GameForm, { type GameFormData } from '@/components/GameForm.vue'
import { useSlowRequestHint } from '@/composables/useSlowRequestHint'

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

onMounted(() => {
  if (!games.loaded) {
    games.fetchAll()
  }
})

async function onSubmit() {
  errors.value = {}
  submitting.value = true

  try {
    await wrap(games.createGame(form))
    router.push({ name: 'dashboard' })
  } catch (err) {
    if (isAxiosError(err) && err.response?.status === 422) {
      // GameForm only renders `errors.general` (it doesn't have a field for
      // every possible backend rule), so flatten the per-field messages into
      // one list instead of silently dropping them like before.
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
  <div class="add-game">
    <h1>Añadir juego</h1>

    <form @submit.prevent="onSubmit">
      <GameForm v-model="form" :submitting="submitting" submit-label="Guardar juego" :errors="errors" />

      <p v-if="isSlow" class="slow-request-hint">
        Puede tardar unos segundos si el servidor estaba inactivo.
      </p>
    </form>
  </div>
</template>

<style scoped>
.add-game {
  max-width: 520px;
  margin: 0 auto;
}

h1 {
  margin-bottom: var(--space-4);
}
</style>
