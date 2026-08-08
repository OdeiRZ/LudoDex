<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useGamesStore } from '@/stores/games'
import TagInput from '@/components/TagInput.vue'

const router = useRouter()
const games = useGamesStore()

const form = reactive({
  name: '',
  min_players: null as number | null,
  max_players: null as number | null,
  min_playtime_minutes: null as number | null,
  max_playtime_minutes: null as number | null,
  weight: null as number | null,
  is_cooperative: false,
  is_competitive: true,
  has_campaign: false,
  mechanics: [] as string[],
  categories: [] as string[],
  status: 'owned' as 'owned' | 'wishlist',
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
    await games.createGame(form)
    router.push({ name: 'dashboard' })
  } catch {
    errors.value = { general: ['No se ha podido guardar el juego. Revisa los datos.'] }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="add-game">
    <h1>Añadir juego</h1>

    <form class="form card" @submit.prevent="onSubmit">
      <div>
        <label for="name">Nombre</label>
        <input id="name" v-model="form.name" type="text" required />
      </div>

      <div class="field-row">
        <div>
          <label for="min_players">Jugadores (min)</label>
          <input id="min_players" v-model.number="form.min_players" type="number" min="1" />
        </div>
        <div>
          <label for="max_players">Jugadores (max)</label>
          <input id="max_players" v-model.number="form.max_players" type="number" min="1" />
        </div>
      </div>

      <div class="field-row">
        <div>
          <label for="min_playtime">Duración min (minutos)</label>
          <input
            id="min_playtime"
            v-model.number="form.min_playtime_minutes"
            type="number"
            min="1"
          />
        </div>
        <div>
          <label for="max_playtime">Duración max (minutos)</label>
          <input
            id="max_playtime"
            v-model.number="form.max_playtime_minutes"
            type="number"
            min="1"
          />
        </div>
      </div>

      <div>
        <label for="weight">Complejidad (1-5)</label>
        <input id="weight" v-model.number="form.weight" type="number" min="1" max="5" step="0.1" />
      </div>

      <fieldset>
        <legend>Modo de juego</legend>
        <label><input v-model="form.is_cooperative" type="checkbox" /> Cooperativo</label>
        <label><input v-model="form.is_competitive" type="checkbox" /> Competitivo</label>
        <label><input v-model="form.has_campaign" type="checkbox" /> Modo campaña</label>
      </fieldset>

      <TagInput
        v-model="form.mechanics"
        label="Mecánicas"
        list-id="mechanics"
        :suggestions="games.mechanicOptions"
      />

      <TagInput
        v-model="form.categories"
        label="Categorías"
        list-id="categories"
        :suggestions="games.categoryOptions"
      />

      <div>
        <label for="status">Estado</label>
        <select id="status" v-model="form.status">
          <option value="owned">Lo tengo</option>
          <option value="wishlist">Lo quiero</option>
        </select>
      </div>

      <p v-for="message in errors.general" :key="message" role="alert" class="alert alert-error">
        {{ message }}
      </p>

      <button type="submit" class="btn btn-primary" :disabled="submitting">
        {{ submitting ? 'Guardando…' : 'Guardar juego' }}
      </button>
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
