<script setup lang="ts">
import { computed, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import TagInput from '@/components/TagInput.vue'
import { useGamesStore } from '@/stores/games'
import { getLocale } from '@/i18n'
import { translateCategory, normalizeCategory } from '@/i18n/bggCategories'
import { translateMechanic, normalizeMechanic } from '@/i18n/bggMechanics'

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
  base_game_id: string | null
  mechanics: string[]
  categories: string[]
  status: 'owned' | 'wishlist'
}

const props = defineProps<{
  submitting: boolean
  submitLabel: string
  errors: Record<string, string[]>
  // The game currently being edited, if any - excluded from its own "base
  // game" options below (a new game being added has none yet).
  currentGameId?: string | null
}>()

const games = useGamesStore()
const { t } = useI18n()
const form = defineModel<GameFormData>({ required: true })
const locale = computed(() => getLocale())
const translateMechanicLabel = (value: string) => translateMechanic(value, locale.value)
const translateCategoryLabel = (value: string) => translateCategory(value, locale.value)

// Only offered from the user's own collection, not the whole shared
// catalog - the common case is owning both the expansion and its base
// game, and a full-catalog search is a bigger feature left for later.
// Also excludes games that are themselves already an expansion of
// something else - picking one as a base would create a nonsensical
// two-level chain (an expansion of an expansion).
const baseGameOptions = computed(() =>
  [...new Map(games.collection.map((entry) => [entry.game.id, entry.game])).values()]
    .filter((game) => game.id !== props.currentGameId && game.base_game_id === null)
    .sort((a, b) => a.name.localeCompare(b.name)),
)

const bggLookupError = ref<string | null>(null)
const bggLookupLoading = ref(false)
const imageBroken = ref(false)
// BGG's own /thing lookup only ever knows the game's canonical (usually
// English) name and image - it has no idea about the specific edition
// version someone linked in their own BGG collection, which is what a
// profile import actually pulls its (often localized) name/image from.
// Overwriting an already-filled name/image here would silently replace
// that with the generic one, so this only ever fills them in when empty;
// these two flags say when that happened, so the notices below can
// explain why nothing changed instead of leaving it a mystery.
const nameKept = ref(false)
const imageKept = ref(false)

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
  nameKept.value = false
  imageKept.value = false

  try {
    const game = await games.lookupBggGame(form.value.bgg_id)

    form.value.bgg_id = game.bgg_id

    if (form.value.name.trim()) {
      nameKept.value = true
    } else {
      form.value.name = game.name
    }

    if (form.value.image_url?.trim()) {
      imageKept.value = true
    } else {
      form.value.image_url = game.image_url
    }

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
      <p class="hint">{{ $t('gameForm.bggFillHint') }}</p>
      <p v-if="bggLookupError" role="alert" class="alert alert-error">{{ bggLookupError }}</p>
    </fieldset>

    <div class="name-and-image">
      <div class="name-field">
        <label for="name">{{ $t('gameForm.name') }}</label>
        <input id="name" v-model="form.name" type="text" required @input="nameKept = false" />
        <p v-if="nameKept" class="alert alert-info field-kept-notice">
          {{ $t('gameForm.bggNameKeptNotice') }}
        </p>

        <label for="image_url">{{ $t('gameForm.imageUrl') }}</label>
        <input
          id="image_url"
          v-model="form.image_url"
          type="url"
          placeholder="https://…"
          @input="imageBroken = false; imageKept = false"
        />
        <p v-if="imageKept" class="alert alert-info field-kept-notice">
          {{ $t('gameForm.bggImageKeptNotice') }}
        </p>
      </div>

      <img
        v-if="form.image_url && !imageBroken"
        :src="form.image_url"
        alt=""
        class="game-image-preview"
        @error="imageBroken = true"
      />
    </div>

    <!-- Year/rank/rating/weight are all 1-4 digits - the shared .field-row
    layout below (built for two roughly-equal fields like name/players)
    left each of them stretched across half the form's width for almost
    nothing to show. .field-compact caps them to just enough room instead,
    so all four fit comfortably on one line. -->
    <div class="field-row">
      <div class="field-compact">
        <label for="year_published">{{ $t('gameForm.yearPublished') }}</label>
        <input id="year_published" v-model.number="form.year_published" type="number" min="1" />
      </div>
      <div class="field-compact">
        <label for="bgg_rank">{{ $t('gameForm.bggRank') }}</label>
        <input id="bgg_rank" v-model.number="form.bgg_rank" type="number" min="1" />
      </div>
      <div class="field-compact">
        <label for="rating">{{ $t('gameForm.rating') }}</label>
        <input id="rating" v-model.number="form.rating" type="number" min="0" max="10" step="0.01" />
      </div>
      <div class="field-compact">
        <label for="weight">{{ $t('gameForm.weight') }}</label>
        <input id="weight" v-model.number="form.weight" type="number" min="1" max="5" step="0.01" />
      </div>
    </div>

    <!-- Edad recomendada alone took a full-width row for a field that's
    barely wider than its own placeholder, while Estructura (just one
    checkbox) sat in its own mostly-empty fieldset right below it - pairing
    them uses both blocks' spare room instead of wasting two. -->
    <div class="field-row">
      <div class="min-age-field">
        <label for="min_age">{{ $t('gameForm.minAge') }}</label>
        <div class="input-with-suffix">
          <input id="min_age" v-model="form.min_age" type="text" :placeholder="$t('gameForm.minAgePlaceholder')" />
          <span class="input-suffix">{{ $t('gameForm.years') }}</span>
        </div>
      </div>
      <fieldset class="structure-field">
        <legend>{{ $t('gameForm.structureLegend') }}</legend>
        <label><input v-model="form.has_campaign" type="checkbox" /> {{ $t('gameForm.hasCampaign') }}</label>
      </fieldset>
    </div>

    <!-- Min/max are a single range, not two unrelated numbers - a fieldset
    (same grouping already used for Mode/Structure below) reads that way
    at a glance, and matches how the range shows up later on the game's
    own card ("2-4 jugadores") better than two full-width fields stacked
    on top of each other. Each input gets its own small "Min."/"Max."
    caption underneath, since the dash between them alone didn't say which
    side was which. -->
    <div class="field-row">
      <fieldset class="range-field">
        <legend>{{ $t('gameForm.players') }}</legend>
        <div class="range-input">
          <input id="min_players" v-model.number="form.min_players" type="number" min="1" />
          <label for="min_players">{{ $t('gameForm.min') }}</label>
        </div>
        <span class="range-sep">–</span>
        <div class="range-input">
          <input id="max_players" v-model.number="form.max_players" type="number" min="1" />
          <label for="max_players">{{ $t('gameForm.max') }}</label>
        </div>
      </fieldset>

      <fieldset class="range-field">
        <legend>{{ $t('gameForm.playtime') }}</legend>
        <div class="range-input">
          <input id="min_playtime" v-model.number="form.min_playtime_minutes" type="number" min="1" />
          <label for="min_playtime">{{ $t('gameForm.min') }}</label>
        </div>
        <span class="range-sep">–</span>
        <div class="range-input">
          <input id="max_playtime" v-model.number="form.max_playtime_minutes" type="number" min="1" />
          <label for="max_playtime">{{ $t('gameForm.max') }}</label>
        </div>
        <span class="input-suffix">{{ $t('gameForm.minutesShort') }}</span>
      </fieldset>
    </div>

    <fieldset>
      <legend>{{ $t('gameForm.modeLegend') }}</legend>
      <label><input v-model="modeChoice" type="radio" value="cooperative" /> {{ $t('gameForm.cooperative') }}</label>
      <label><input v-model="modeChoice" type="radio" value="competitive" /> {{ $t('gameForm.competitive') }}</label>
      <label><input v-model="modeChoice" type="radio" value="both" /> {{ $t('gameForm.both') }}</label>
    </fieldset>

    <div>
      <label for="base_game_id">{{ $t('gameForm.baseGame') }}</label>
      <select id="base_game_id" v-model="form.base_game_id">
        <option :value="null">{{ $t('gameForm.baseGameNone') }}</option>
        <option v-for="game in baseGameOptions" :key="game.id" :value="game.id">{{ game.name }}</option>
      </select>
    </div>

    <TagInput
      v-model="form.mechanics"
      :label="$t('gameForm.mechanics')"
      list-id="mechanics"
      :suggestions="games.mechanicOptions"
      :translate="translateMechanicLabel"
      :normalize="normalizeMechanic"
    />

    <TagInput
      v-model="form.categories"
      :label="$t('gameForm.categories')"
      list-id="categories"
      :suggestions="games.categoryOptions"
      :translate="translateCategoryLabel"
      :normalize="normalizeCategory"
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

.hint {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.name-and-image {
  display: flex;
  gap: var(--space-4);
  align-items: center;
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
  width: 120px;
  height: 120px;
  /* .name-and-image's align-items: center centers this against the whole
  row, but the row's actual content is the name input sitting above the
  image URL input (with its own label to account for) - centering purely
  by height alone. This nudges it down to sit centered against those two
  inputs specifically instead, which reads better against their combined
  label-plus-input weight. */
  margin-top: 29px;
  object-fit: cover;
  border-radius: var(--radius-sm);
  border: 1px solid var(--color-border-strong);
  flex-shrink: 0;
}

/* Overrides .field-row's default flex:1/min-width:140px, which was sized
for roughly-equal fields like name/image - a 1-4 digit number doesn't need
anywhere near that much room, just a bit more than the previous, tighter
100px. Capped at 108px rather than higher still, since four of these share
one row (.card's own padding leaves ~488px for them) - flex-wrap moves a
whole field down to its own line rather than shrinking to fit once the
basis for all four together stops fitting, so this is close to the most
room they can have without "Complejidad" falling onto a second line. */

.field-compact {
  flex: 0 1 108px;
  min-width: 90px;
}

.range-field {
  flex: 0 1 auto;
  min-width: 0;
  align-items: flex-start;
}

.range-field input {
  width: 70px;
  flex: 0 0 auto;
}

/* Min./Max. sit right under their own input rather than off to the side,
so it's unambiguous which one they're labelling even once the fieldset
also wraps to a second line on a narrow screen. */
.range-input {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.range-input label {
  margin: var(--space-1) 0 0;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

/* .range-field's align-items: flex-start (needed to keep the Min./Max.
captions from pulling the whole row down) also top-aligns this against
the row - and align-self: center previously used here centers against
the row's full height (input + caption below it), which lands below the
input's own vertical center rather than on it. Fixed offset instead,
measured against just the input's own height. */
.range-sep {
  margin-top: 9px;
  color: var(--color-text-muted);
}

/* Same top-alignment issue as .range-sep above, and the same fix - a
plain .input-suffix (no override) is fine elsewhere, since that's only
ever paired with a single input in a row that's already align-items:
center (see "años" next to min_age). */
.range-field .input-suffix {
  margin-top: 10px;
}

/* At a real 360-366px phone, Edad recomendada and Estructura were
stacking onto their own separate lines instead of sharing the row:
.field-row's shared min-width: 140px per child needs 296px for the
two of them together, more than this card's real ~283px content
width. Edad recomendada's own label ("Edad recomendada", 122px) and
Estructura's checkbox label ("Modo campaña", 116px plus the
fieldset's own padding) both fit comfortably once that floor is
dropped and flex: 1 (already the default for .field-row's children)
is left to split the row's real width between them - min-width: 0 is
needed on both, not just the fieldset, since the shared rule's 140px
is an explicit floor an unadorned div would otherwise keep too.
Estructura's own padding is trimmed a bit further, the same fix
already used for the picker's own Estructura field, since a
fieldset's padding counts against its automatic minimum content size
the same way its legend/label content does. */
@media (max-width: 366px) {
  .min-age-field,
  .structure-field {
    min-width: 0;
  }

  .structure-field {
    padding-left: var(--space-2);
    padding-right: var(--space-2);
  }

  /* Año de juego/Ranking en BGG/Valoración/Complejidad share this
  card's own ~283px content width two at a time (four never fit on
  one line even at their normal 108px, so this only ever affects
  which two share a line) - 108px left "Ranking en BGG" and
  "Valoración (0-10)" only 1-2px of real margin over their own
  measured single-line width, thin enough that a real phone's font
  rendering (not just this simulated width) tips them onto a second
  line. Widened enough to leave real breathing room instead of a
  margin that only works in theory. */
  .field-compact {
    flex-basis: 118px;
  }
}
</style>
