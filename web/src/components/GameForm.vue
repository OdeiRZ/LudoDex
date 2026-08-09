<script setup lang="ts">
import { computed, ref } from 'vue'
import { isAxiosError } from 'axios'
import TagInput from '@/components/TagInput.vue'
import { useGamesStore } from '@/stores/games'

export interface GameFormData {
  name: string
  image_url: string | null
  min_players: number | null
  max_players: number | null
  min_playtime_minutes: number | null
  max_playtime_minutes: number | null
  weight: number | null
  is_cooperative: boolean
  is_competitive: boolean
  has_campaign: boolean
  mechanics: string[]
  categories: string[]
  status: 'owned' | 'wishlist'
}

defineProps<{
  submitting: boolean
  submitLabel: string
  errors: Record<string, string[]>
}>()

const games = useGamesStore()
const form = defineModel<GameFormData>({ required: true })

const bggId = ref<number | null>(null)
const bggLookupError = ref<string | null>(null)
const bggLookupLoading = ref(false)
const imageBroken = ref(false)

// UI-only concept: the two flags stay independent in the data (a team game
// can genuinely be both cooperative and competitive - see the games
// migration's own comment and BggImportService), but as a *choice someone
// is making while filling in a form*, "pick one" reads better than two
// unrelated checkboxes, so this radio computes to/from the pair of booleans
// instead of adding a third "mode" field to the payload.
type ModeChoice = 'cooperative' | 'competitive' | 'both' | null

const modeChoice = computed<ModeChoice>({
  get() {
    if (form.value.is_cooperative && form.value.is_competitive) return 'both'
    if (form.value.is_cooperative) return 'cooperative'
    if (form.value.is_competitive) return 'competitive'
    return null
  },
  set(value) {
    form.value.is_cooperative = value === 'cooperative' || value === 'both'
    form.value.is_competitive = value === 'competitive' || value === 'both'
  },
})

async function onLookupBgg() {
  if (!bggId.value) {
    return
  }

  bggLookupError.value = null
  bggLookupLoading.value = true

  try {
    const game = await games.lookupBggGame(bggId.value)

    form.value.name = game.name
    form.value.image_url = game.image_url
    form.value.min_players = game.min_players
    form.value.max_players = game.max_players
    form.value.min_playtime_minutes = game.min_playtime_minutes
    form.value.max_playtime_minutes = game.max_playtime_minutes
    form.value.weight = game.weight
    form.value.mechanics = game.mechanics
    form.value.categories = game.categories
    imageBroken.value = false
  } catch (err) {
    bggLookupError.value = isAxiosError(err)
      ? err.response?.data.message
      : 'No se ha podido consultar BoardGameGeek.'
  } finally {
    bggLookupLoading.value = false
  }
}
</script>

<template>
  <div class="form card">
    <fieldset class="bgg-lookup">
      <legend>Importar de BoardGameGeek (opcional)</legend>
      <div class="bgg-lookup-row">
        <input
          v-model.number="bggId"
          type="number"
          min="1"
          placeholder="Id del juego en BGG"
          aria-label="Id del juego en BGG"
        />
        <button
          type="button"
          class="btn"
          :disabled="!bggId || bggLookupLoading"
          @click="onLookupBgg"
        >
          {{ bggLookupLoading ? 'Buscando…' : 'Rellenar desde BGG' }}
        </button>
      </div>
      <p v-if="bggLookupError" role="alert" class="alert alert-error">{{ bggLookupError }}</p>
    </fieldset>

    <div class="name-and-image">
      <div class="name-field">
        <label for="name">Nombre</label>
        <input id="name" v-model="form.name" type="text" required />

        <label for="image_url">URL de la imagen</label>
        <input
          id="image_url"
          v-model="form.image_url"
          type="url"
          placeholder="https://…"
          @input="imageBroken = false"
        />
      </div>

      <img
        v-if="form.image_url && !imageBroken"
        :src="form.image_url"
        alt=""
        class="game-image-preview"
        @error="imageBroken = true"
      />
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
        <input id="min_playtime" v-model.number="form.min_playtime_minutes" type="number" min="1" />
      </div>
      <div>
        <label for="max_playtime">Duración max (minutos)</label>
        <input id="max_playtime" v-model.number="form.max_playtime_minutes" type="number" min="1" />
      </div>
    </div>

    <div>
      <label for="weight">Complejidad (1-5)</label>
      <input id="weight" v-model.number="form.weight" type="number" min="1" max="5" step="0.1" />
    </div>

    <fieldset>
      <legend>Modo</legend>
      <label><input v-model="modeChoice" type="radio" value="cooperative" /> Cooperativo</label>
      <label><input v-model="modeChoice" type="radio" value="competitive" /> Competitivo</label>
      <label><input v-model="modeChoice" type="radio" value="both" /> Ambos (p. ej. por equipos)</label>
    </fieldset>

    <fieldset>
      <legend>Estructura</legend>
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
      {{ submitLabel }}
    </button>
  </div>
</template>

<style scoped>
.bgg-lookup-row {
  display: flex;
  gap: var(--space-2);
  width: 100%;
}

.bgg-lookup-row input {
  flex: 1;
}

.name-and-image {
  display: flex;
  gap: var(--space-4);
  align-items: flex-start;
}

.name-field {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.name-field label:not(:first-child) {
  margin-top: var(--space-2);
}

.game-image-preview {
  width: 96px;
  height: 96px;
  object-fit: cover;
  border-radius: var(--radius-sm);
  border: 1px solid var(--color-border-strong);
  flex-shrink: 0;
}
</style>
