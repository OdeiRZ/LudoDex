<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { isAxiosError } from 'axios'
import { useGamesStore } from '@/stores/games'
import { useToastStore } from '@/stores/toast'
import GameForm, { type GameFormData } from '@/components/GameForm.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { useSlowRequestHint } from '@/composables/useSlowRequestHint'

const props = defineProps<{ id: string }>()

const router = useRouter()
const games = useGamesStore()
const toast = useToastStore()
const { t } = useI18n()
const { isSlow, wrap } = useSlowRequestHint()

// Editing is only reachable from the collection now (the picker's own
// edit shortcut was replaced by a read-only details view), so both
// "cancel" and "save" always return there.
const returnTo = { name: 'dashboard' }

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
  base_game_id: null,
  mechanics: [],
  categories: [],
  status: 'owned',
})

const errors = ref<Record<string, string[]>>({})
const submitting = ref(false)
const deleting = ref(false)

// A single click deletes immediately elsewhere in the app (the collection's
// own "Quitar" button), which is fine when the game is right there in front
// of you - but this button sits at the bottom of a whole form of fields to
// review, so a lightweight "click again to confirm" guards against a stray
// click while scrolling past it without adding a whole confirmation dialog.
const confirmingDelete = ref(false)
let confirmingDeleteTimeout: ReturnType<typeof setTimeout> | undefined

function onDeleteClick() {
  if (!confirmingDelete.value) {
    confirmingDelete.value = true
    confirmingDeleteTimeout = setTimeout(() => {
      confirmingDelete.value = false
    }, 4000)
    return
  }

  clearTimeout(confirmingDeleteTimeout)
  onDelete()
}

onUnmounted(() => clearTimeout(confirmingDeleteTimeout))

const entry = computed(() => games.collection.find((item) => item.id === props.id))

// Snapshotted at the end of fillFormFromEntry (below) rather than once up
// front - the form starts at its empty defaults before the entry has even
// loaded, so "dirty" only means anything once there's real data to compare
// against.
let pristineSnapshot = JSON.stringify(form)
const isDirty = computed(() => JSON.stringify(form) !== pristineSnapshot)

function warnBeforeUnload(event: BeforeUnloadEvent) {
  if (isDirty.value) {
    event.preventDefault()
    event.returnValue = ''
  }
}

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
  form.base_game_id = game.base_game_id
  form.mechanics = [...game.mechanics]
  form.categories = [...game.categories]
  form.status = status

  pristineSnapshot = JSON.stringify(form)
}

onMounted(async () => {
  if (!games.loaded) {
    await games.fetchAll()
  }

  fillFormFromEntry()
  window.addEventListener('beforeunload', warnBeforeUnload)
})

onUnmounted(() => {
  window.removeEventListener('beforeunload', warnBeforeUnload)
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
    router.push(returnTo)
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
    router.push(returnTo)
  } catch {
    errors.value = { general: [t('editGame.deleteError')] }
    confirmingDelete.value = false
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <div class="edit-game">
    <div class="page-header">
      <h1>{{ $t('editGame.title') }}</h1>
      <RouterLink :to="returnTo" class="back-link">{{ $t('backLink') }}</RouterLink>
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
        :current-game-id="entry?.game.id"
      />

      <p v-if="isSlow" class="slow-request-hint">
        <LoadingSpinner :size="16" />
        {{ $t('common.coldStartHint') }}
      </p>
    </form>

    <div v-if="entry" class="danger-zone">
      <button
        type="button"
        class="btn btn-danger"
        :class="{ 'btn-danger-confirm': confirmingDelete }"
        :disabled="submitting || deleting"
        @click="onDeleteClick"
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
        <template v-if="confirmingDelete">
          {{ $t('editGame.deleteConfirmShort') }}
          <span class="delete-confirm-detail">{{ $t('editGame.deleteConfirmDetail') }}</span>
        </template>
        <template v-else>{{ $t('editGame.delete') }}</template>
      </button>

      <p v-if="deleting" class="loading-state">
        <LoadingSpinner :size="16" />
        {{ $t('editGame.deleting') }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.edit-game {
  max-width: 520px;
  margin: 0 auto;
}

/* Volver used to sit on its own line above the title - baseline
alignment (rather than center) is what keeps it reading level with h1
despite the large size difference between them, matching how the
title row elsewhere in the app (e.g. the picker's own h1 + count)
already aligns text of very different sizes. */
.page-header {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-4);
  margin-bottom: var(--space-4);
}

.back-link {
  display: inline-block;
  padding-right: 10px;
  color: var(--color-text-muted);
  font-size: 0.9rem;
  white-space: nowrap;
}

h1 {
  margin-bottom: 0;
}

.danger-zone {
  margin-top: var(--space-6);
  /* Left/right match .card's own padding below - .danger-zone sits outside
  the card (it isn't part of the save flow), so without this its button
  would run wider than "Guardar cambios" instead of lining up with it. */
  padding: var(--space-4) var(--space-4) 0;
  border-top: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

/* Matches the submit button's own full-width look (it stretches to fill
.form's flex column by default) instead of shrinking to fit its label. */
.danger-zone .btn {
  width: 100%;
}

.danger-zone .btn svg {
  width: 16px;
  height: 16px;
  margin-right: var(--space-1);
}

/* "¿Seguro? Toca de nuevo para eliminar" is the clearer label, but on
a real 366px phone (and narrower) it's the longest text on the page's
narrowest button - the icon plus the short "¿Seguro?" already carries
the same meaning the icon-only "Eliminar juego" state doesn't need
spelling out further at this width. */
@media (max-width: 366px) {
  .delete-confirm-detail {
    display: none;
  }
}
</style>
