<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { isAxiosError } from 'axios'
import { useGamesStore } from '@/stores/games'
import { useToastStore } from '@/stores/toast'
import GameForm, { type GameFormData } from '@/components/GameForm.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { useSlowRequestHint } from '@/composables/useSlowRequestHint'

const props = defineProps<{ id: string }>()

const route = useRoute()
const router = useRouter()
const games = useGamesStore()
const toast = useToastStore()
const { t } = useI18n()
const { isSlow, wrap } = useSlowRequestHint()

// This view is reachable from both the collection and the picker's own
// edit shortcut - `from` says which, so "cancel" and "save" both return
// to wherever the user actually came from instead of always landing on
// the collection. Falls back to the collection when absent (e.g. the
// link was opened directly rather than navigated to in-app).
const returnTo = computed(() =>
  route.query.from === 'picker' ? { name: 'picker' } : { name: 'dashboard' },
)

const form = reactive<GameFormData>({
  name: '',
  image_url: null,
  bgg_id: null,
  year_published: null,
  min_age: null,
  bgg_rank: null,
  rating: null,
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
const deleting = ref(false)

const entry = computed(() => games.collection.find((item) => item.id === props.id))

function fillFormFromEntry() {
  if (!entry.value) {
    return
  }

  const { game, status } = entry.value

  form.name = game.name
  form.image_url = game.image_url
  form.bgg_id = game.bgg_id
  form.year_published = game.year_published
  form.min_age = game.min_age
  form.bgg_rank = game.bgg_rank
  form.rating = game.rating
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
    toast.show(t('editGame.toastSaved'))
    router.push(returnTo.value)
  } catch (err) {
    if (isAxiosError(err) && err.response?.status === 422) {
      const fieldErrors: Record<string, string[]> = err.response.data.errors
      errors.value = { general: Object.values(fieldErrors).flat() }
    } else {
      errors.value = { general: [t('common.genericGameSaveError')] }
    }
  } finally {
    submitting.value = false
  }
}

async function onDelete() {
  errors.value = {}
  deleting.value = true

  try {
    await games.deleteGame(props.id)
    toast.show(t('dashboard.toastRemoved'))
    router.push(returnTo.value)
  } catch {
    errors.value = { general: [t('editGame.deleteError')] }
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div class="edit-game">
    <RouterLink :to="returnTo" class="back-link">{{ $t('backLink') }}</RouterLink>

    <div class="title-row">
      <h1>{{ $t('editGame.title') }}</h1>
      <!-- Icon rather than a labeled button, and set apart from the save
      flow entirely - deleting is the rare, irreversible exception here,
      not a form action, so it reads better as something you do to the
      game itself (next to its title) than as a button that could get
      mistaken for part of submitting the form. -->
      <button
        v-if="entry"
        type="button"
        class="delete-icon-button"
        :disabled="submitting || deleting"
        :aria-label="$t('editGame.delete')"
        :title="$t('editGame.delete')"
        @click="onDelete"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6h16Z"
          />
          <line x1="10" y1="11" x2="10" y2="17" stroke-linecap="round" />
          <line x1="14" y1="11" x2="14" y2="17" stroke-linecap="round" />
        </svg>
      </button>
    </div>

    <p v-if="games.loading" class="loading-state">
      <LoadingSpinner :size="28" />
      {{ $t('common.loading') }}
    </p>

    <p v-else-if="!entry" class="empty-state">
      {{ $t('editGame.notFound') }}
    </p>

    <form v-else @submit.prevent="onSubmit">
      <GameForm
        v-model="form"
        :submitting="submitting"
        :submit-label="$t('editGame.submit')"
        :errors="errors"
      />

      <p v-if="isSlow" class="slow-request-hint">
        <LoadingSpinner :size="16" />
        {{ $t('common.coldStartHint') }}
      </p>
    </form>

    <p v-if="deleting" class="loading-state">
      <LoadingSpinner :size="16" />
      {{ $t('editGame.deleting') }}
    </p>
  </div>
</template>

<style scoped>
.edit-game {
  max-width: 520px;
  margin: 0 auto;
}

.back-link {
  display: inline-block;
  margin-bottom: var(--space-2);
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--space-4);
}

.delete-icon-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-danger);
}

.delete-icon-button:hover:not(:disabled) {
  background: var(--color-danger);
  color: #fff;
}

.delete-icon-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.delete-icon-button svg {
  width: 16px;
  height: 16px;
}
</style>
