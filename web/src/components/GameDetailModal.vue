<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useGamesStore, type Game } from '@/stores/games'
import { FALLBACK_ICON_URL } from '@/lib/assets'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const props = defineProps<{ game: Game }>()
const emit = defineEmits<{ close: [] }>()
const games = useGamesStore()

// Prefer the Spanish translation once it exists - until the translation
// step (DeepL, triggered by the button below) actually runs for this
// game, description_es stays null and this silently falls back to the
// original English text instead of showing nothing.
const displayDescription = computed(() => props.game.description_es || props.game.description)
const isUntranslated = computed(() => !props.game.description_es && !!props.game.description)

const translating = ref(false)
const translateFailed = ref(false)

// Mutates the same reactive Game object this component's own `game` prop
// points to (games.collection isn't cloned when PickerView builds the
// list this modal's caller picks entries from) - displayDescription
// above updates on its own once that happens, no local state to sync by
// hand here.
async function onTranslateClick() {
  translating.value = true
  translateFailed.value = false

  try {
    await games.translateDescription(props.game.id)
  } catch {
    translateFailed.value = true
  } finally {
    translating.value = false
  }
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    emit('close')
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="modal-backdrop" @click.self="$emit('close')">
    <div class="modal-panel card" role="dialog" aria-modal="true" :aria-label="game.name">
      <button
        type="button"
        class="btn modal-close"
        :aria-label="$t('common.close')"
        :title="$t('common.close')"
        @click="$emit('close')"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M18 6 6 18M6 6l12 12" />
        </svg>
      </button>

      <div class="modal-cover">
        <img v-if="game.image_url" :src="game.image_url" alt="" />
        <div v-else class="modal-cover-fallback">
          <img :src="FALLBACK_ICON_URL" alt="" />
        </div>
      </div>

      <h2>{{ game.name }}</h2>

      <p v-if="displayDescription" class="modal-description">
        <span v-if="isUntranslated" class="badge badge-en" :title="$t('picker.descriptionUntranslated')">{{
          $t('picker.descriptionUntranslatedShort')
        }}</span>
        {{ displayDescription }}
      </p>
      <p v-else class="modal-description-empty">{{ $t('picker.noDescription') }}</p>

      <button
        v-if="isUntranslated"
        type="button"
        class="btn modal-translate"
        :disabled="translating"
        @click="onTranslateClick"
      >
        <LoadingSpinner v-if="translating" :size="14" />
        {{ translating ? $t('picker.translating') : $t('picker.translateButton') }}
      </button>
      <p v-if="translateFailed" role="alert" class="alert alert-error modal-translate-error">
        {{ $t('picker.translateError') }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-4);
  background: rgba(15, 23, 42, 0.6);
}

.modal-panel {
  position: relative;
  width: 100%;
  max-width: 480px;
  max-height: 90vh;
  overflow-y: auto;
}

.modal-close {
  position: absolute;
  top: var(--space-3);
  right: var(--space-3);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  padding: 0;
  border-radius: var(--radius-pill);
  background: var(--color-surface);
}

.modal-close svg {
  width: 16px;
  height: 16px;
}

.modal-cover {
  margin: calc(var(--space-4) * -1) calc(var(--space-4) * -1) var(--space-4);
  border-radius: var(--radius) var(--radius) 0 0;
  overflow: hidden;
  background: var(--color-surface-hover);
}

/* No fixed height/object-fit: cover here on purpose - unlike the grid
cards (which all need to line up at the same size), this is the one
place showing the cover at its own real proportions instead of cropped
to fit a box. max-height is just a safety net for an unusually tall
cover so it can't push the rest of the modal off-screen; object-fit:
contain only actually does anything once that cap kicks in (letterboxes
instead of cropping), since width: 100%/height: auto alone already
renders at the image's natural ratio with no mismatch to resolve. */
.modal-cover img {
  display: block;
  width: 100%;
  height: auto;
  max-height: 60vh;
  object-fit: contain;
}

.modal-cover-fallback {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 180px;
}

.modal-cover-fallback img {
  width: 56px;
  height: 56px;
  object-fit: contain;
  opacity: 0.4;
}

h2 {
  padding-right: var(--space-6);
  margin-bottom: var(--space-3);
}

.modal-description {
  white-space: pre-line;
  color: var(--color-text);
  line-height: 1.6;
}

.modal-description-empty {
  color: var(--color-text-muted);
  font-style: italic;
}

/* Same small-badge sizing as the picker's expansion/status badges, but
its own color - "EN" isn't a status the rest of the app already has a
color for, and reusing one of those (teal/amber/violet) would wrongly
suggest a connection to owned/wishlist/expansion. */
.badge-en {
  display: inline-block;
  margin-right: var(--space-2);
  padding: 1px var(--space-2);
  border-radius: var(--radius-sm);
  background: var(--color-border-strong);
  color: var(--color-text);
  font-size: 0.75rem;
  font-weight: 600;
  vertical-align: text-top;
}

.modal-translate {
  margin-top: var(--space-4);
}

.modal-translate-error {
  margin-top: var(--space-2);
}
</style>
