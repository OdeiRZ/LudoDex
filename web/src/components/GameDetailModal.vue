<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useGamesStore } from '@/stores/games'
import { FALLBACK_ICON_URL } from '@/lib/assets'
import { getLocale } from '@/i18n'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

// Only the fields this modal actually renders - narrower than the full
// Game type on purpose, so callers whose own game shape doesn't carry
// mechanics/categories/etc. (Partidas' own play.game, which is
// intentionally a slimmer projection - see PlayResource) can reuse this
// modal without needing to fake the rest of Game just to satisfy the
// prop type.
export interface DetailGame {
  id: string
  name: string
  image_url: string | null
  description: string | null
  description_es: string | null
}

const props = defineProps<{ game: DetailGame }>()
const emit = defineEmits<{ close: []; translated: [descriptionEs: string | null] }>()
const games = useGamesStore()

// A local copy, seeded from the prop, rather than reading
// props.game.description_es directly everywhere below - lets
// displayDescription/isUntranslated update immediately off the store
// action's own return value without waiting on a prop change to flow
// back down.
const descriptionEs = ref(props.game.description_es)

// Only prefers the Spanish translation when the app itself is in
// Spanish - description_es existing doesn't mean someone reading the
// app in English wants the Spanish text instead of the original, and
// falls back to English if a translation isn't there yet either way.
const displayDescription = computed(() =>
  getLocale() === 'es' ? descriptionEs.value || props.game.description : props.game.description,
)

// Neither the "still in English" badge nor the button to fix that mean
// anything to someone who's already set the app to English themselves -
// they can read the original just fine, and offering to translate it
// into Spanish for them specifically would be backwards.
const isUntranslated = computed(
  () => getLocale() === 'es' && !descriptionEs.value && !!props.game.description,
)

const translating = ref(false)
const translateFailed = ref(false)

// Also emits the result (rather than mutating props.game.description_es
// directly - Vue/ESLint flags mutating a prop, even a nested field of
// one) so a caller whose own list isn't already covered by the store
// action's own games.collection side effect can still persist it -
// Partidas' plays.entries never was covered by that (its own play.game
// isn't part of games.collection), so before this, closing and
// reopening this same game's modal there kept showing English again
// despite the DB already having the translation saved. Picker/
// Dashboard don't need to listen for this event themselves - their own
// entry already gets the collection-side mutation regardless.
async function onTranslateClick() {
  translating.value = true
  translateFailed.value = false

  try {
    descriptionEs.value = await games.translateDescription(props.game.id)
    emit('translated', descriptionEs.value)
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

// Without this, scrolling inside the modal on a touch device (or over
// the backdrop with a wheel) also scrolls the page underneath - the
// backdrop covers the viewport but isn't itself scrollable, so the
// gesture bubbles straight through to it. Restores whatever the body's
// own overflow was before (rather than assuming it was the default
// 'visible') in case something else already set it.
let previousBodyOverflow = ''

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  previousBodyOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = previousBodyOverflow
})
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
        <LoadingSpinner v-if="translating" :size="22" />
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
  /* Without this, dragging past the top/bottom of this panel's own
  scroll on a touch device chains into scrolling the page behind it
  instead of just stopping - the body's own overflow: hidden (see the
  script block) blocks the wheel/scrollbar path, but not this one. */
  overscroll-behavior: contain;
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
  box-sizing: border-box;
  width: 100%;
  height: auto;
  max-height: 60vh;
  object-fit: contain;
  border-style: solid;
  border-color: transparent;
  border-width: 5px 5px 0 5px;
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
