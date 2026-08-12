<script setup lang="ts">
import { computed, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import TagInput from '@/components/TagInput.vue'
import { useGamesStore } from '@/stores/games'

export interface GameFormData {
  name: string
  image_url: string | null
  bgg_id: number | null
  year_published: number | null
  min_age: string | null
  bgg_rank: number | null
  rating: number | null
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
const { t } = useI18n()
const form = defineModel<GameFormData>({ required: true })

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
  if (!form.value.bgg_id) {
    return
  }

  bggLookupError.value = null
  bggLookupLoading.value = true

  try {
    const game = await games.lookupBggGame(form.value.bgg_id)

    form.value.bgg_id = game.bgg_id
    form.value.name = game.name
    form.value.image_url = game.image_url
    form.value.year_published = game.year_published
    form.value.min_age = game.min_age
    form.value.bgg_rank = game.bgg_rank
    form.value.rating = game.rating
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
      : t('gameForm.bggLookupGenericError')
  } finally {
    bggLookupLoading.value = false
  }
}
</script>

<template>
  <div class="form card">
    <fieldset class="bgg-lookup">
      <legend>{{ $t('gameForm.bggImportLegend') }}</legend>
      <div class="bgg-lookup-row">
        <input
          v-model.number="form.bgg_id"
          type="number"
          min="1"
          :placeholder="$t('gameForm.bggIdPlaceholder')"
          :aria-label="$t('gameForm.bggIdPlaceholder')"
        />
        <button
          type="button"
          class="btn"
          :disabled="!form.bgg_id || bggLookupLoading"
          @click="onLookupBgg"
        >
          {{ bggLookupLoading ? $t('gameForm.bggFillLoading') : $t('gameForm.bggFillButton') }}
        </button>
      </div>
      <p v-if="bggLookupError" role="alert" class="alert alert-error">{{ bggLookupError }}</p>
    </fieldset>

    <div class="name-and-image">
      <div class="name-field">
        <label for="name">{{ $t('gameForm.name') }}</label>
        <input id="name" v-model="form.name" type="text" required />

        <label for="image_url">{{ $t('gameForm.imageUrl') }}</label>
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
        <label for="year_published">{{ $t('gameForm.yearPublished') }}</label>
        <input id="year_published" v-model.number="form.year_published" type="number" min="1" />
      </div>
      <div>
        <label for="min_age">{{ $t('gameForm.minAge') }}</label>
        <div class="input-with-suffix">
          <input id="min_age" v-model="form.min_age" type="text" :placeholder="$t('gameForm.minAgePlaceholder')" />
          <span class="input-suffix">{{ $t('gameForm.years') }}</span>
        </div>
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="bgg_rank">{{ $t('gameForm.bggRank') }}</label>
        <input id="bgg_rank" v-model.number="form.bgg_rank" type="number" min="1" />
      </div>
      <div>
        <label for="rating">{{ $t('gameForm.rating') }}</label>
        <input id="rating" v-model.number="form.rating" type="number" min="0" max="10" step="0.01" />
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="min_players">{{ $t('gameForm.minPlayers') }}</label>
        <input id="min_players" v-model.number="form.min_players" type="number" min="1" />
      </div>
      <div>
        <label for="max_players">{{ $t('gameForm.maxPlayers') }}</label>
        <input id="max_players" v-model.number="form.max_players" type="number" min="1" />
      </div>
    </div>

    <div class="field-row">
      <div>
        <label for="min_playtime">{{ $t('gameForm.minPlaytime') }}</label>
        <input id="min_playtime" v-model.number="form.min_playtime_minutes" type="number" min="1" />
      </div>
      <div>
        <label for="max_playtime">{{ $t('gameForm.maxPlaytime') }}</label>
        <input id="max_playtime" v-model.number="form.max_playtime_minutes" type="number" min="1" />
      </div>
    </div>

    <div>
      <label for="weight">{{ $t('gameForm.weight') }}</label>
      <input id="weight" v-model.number="form.weight" type="number" min="1" max="5" step="0.01" />
    </div>

    <fieldset>
      <legend>{{ $t('gameForm.modeLegend') }}</legend>
      <label><input v-model="modeChoice" type="radio" value="cooperative" /> {{ $t('gameForm.cooperative') }}</label>
      <label><input v-model="modeChoice" type="radio" value="competitive" /> {{ $t('gameForm.competitive') }}</label>
      <label><input v-model="modeChoice" type="radio" value="both" /> {{ $t('gameForm.both') }}</label>
    </fieldset>

    <fieldset>
      <legend>{{ $t('gameForm.structureLegend') }}</legend>
      <label><input v-model="form.has_campaign" type="checkbox" /> {{ $t('gameForm.hasCampaign') }}</label>
    </fieldset>

    <TagInput
      v-model="form.mechanics"
      :label="$t('gameForm.mechanics')"
      list-id="mechanics"
      :suggestions="games.mechanicOptions"
    />

    <TagInput
      v-model="form.categories"
      :label="$t('gameForm.categories')"
      list-id="categories"
      :suggestions="games.categoryOptions"
    />

    <div>
      <label for="status">{{ $t('gameForm.status') }}</label>
      <select id="status" v-model="form.status">
        <option value="owned">{{ $t('gameForm.owned') }}</option>
        <option value="wishlist">{{ $t('gameForm.wishlist') }}</option>
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

.input-with-suffix {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.input-with-suffix input {
  flex: 1;
  min-width: 0;
}

.input-suffix {
  color: var(--color-text-muted);
  font-size: 0.85rem;
  white-space: nowrap;
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
