<script setup lang="ts">
import { ref, watch } from 'vue'
import { FALLBACK_ICON_URL } from '@/lib/assets'

const props = withDefaults(
  defineProps<{
    imageUrl?: string | null
    compact?: boolean
    isExpansion?: boolean
  }>(),
  { imageUrl: null, compact: false, isExpansion: false },
)

// Same broken-image fallback the old small thumbnail had: a missing or
// 404ing image_url (e.g. a stale BGG link) shows the app's own die icon
// centered on a plain background instead of a broken-image glyph or an
// empty card.
const showFallback = ref(false)

watch(
  () => props.imageUrl,
  () => {
    showFallback.value = false
  },
)
</script>

<template>
  <div class="game-cover" :class="{ compact, expansion: isExpansion }">
    <img
      v-if="imageUrl && !showFallback"
      :src="imageUrl"
      alt=""
      class="cover-image"
      loading="lazy"
      @error="showFallback = true"
    />
    <div v-else class="cover-fallback">
      <img :src="FALLBACK_ICON_URL" alt="" class="cover-fallback-icon" />
    </div>

    <div class="cover-scrim">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.game-cover {
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  height: 100%;
  min-height: 190px;
  border-radius: var(--radius);
  overflow: hidden;
  background: var(--color-surface-hover);
  box-shadow: var(--shadow-card);
}

/* Left border rather than a corner ribbon or full outline - reads at a
glance without needing a new icon/image asset, and a straight border
naturally follows .game-cover's own border-radius instead of needing its
own separate positioning to fit every card size/breakpoint here. Violet
because every other color already means something else on this card
(teal is the owned status, amber is wishlist/cooperative, red is the
remove button). */
.game-cover.expansion {
  border-left: 4px solid var(--color-expansion);
}

.game-cover.compact {
  min-height: 120px;
}

.cover-image {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: 50% 20%;
}

.cover-fallback {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.cover-fallback-icon {
  width: 56px;
  height: 56px;
  opacity: 0.4;
}

/* Always a dark scrim regardless of light/dark theme - it sits over an
arbitrary photo, not the app's own background, so it needs its own fixed
contrast rather than the theme's card colors. Never fades to fully
transparent at its own top edge (unlike the old 100%-transparent version) -
that edge is where the title sits, and a card with more content below it
(wrapped badges, longer meta text) pushes the title further up into a
taller scrim, landing it in a lighter part of the gradient on a
percentage-based fade. The text-shadow below is the real guarantee though:
it keeps the title/meta legible even in the worst case (a bright, busy
part of the source photo right behind the text), independent of the
gradient or how tall the scrim ends up being. */
.cover-scrim {
  position: relative;
  /* No explicit z-index (was 1) - deliberately, so this never establishes
  its own stacking context: .cover-image/.cover-fallback (both z-index:
  auto, earlier in the DOM) still paint underneath purely from DOM order,
  which needs no z-index at all. An explicit z-index here would trap any
  z-index given to .details-icon-button inside it (see that button's own
  comment in DashboardView.vue/PickerView.vue) - confirmed directly: with
  z-index: 1 here, that button's own z-index: 21 was silently capped at
  this element's "1", still losing to .az-scrubber's 20 on a narrow phone
  where the two visually overlap by ~13px (measured via
  getBoundingClientRect). Raising this z-index instead (tried first) does
  free the button, but also raises the ENTIRE card body - including all
  the plain (non-interactive) space the scrubber passes over on its way
  down the list - above the scrubber, breaking its own tap/drag targets
  almost everywhere except the gaps between cards (confirmed directly by
  sampling elementFromPoint down the scrubber's full height: only 2 of 9
  points still hit the scrubber afterward, the other 7 hit card content
  instead). */
  padding: var(--space-3);
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  color: #fff;
  text-shadow:
    0 1px 2.5px rgba(0, 0, 0, 0.8),
    0 1px 6.5px rgba(0, 0, 0, 0.4);
  background: linear-gradient(
    to top,
    rgba(15, 23, 42, 0.9) 0%,
    rgba(15, 23, 42, 0.75) 60%,
    rgba(15, 23, 42, 0.35) 100%
  );
}

.cover-scrim :deep(h2) {
  color: #fff;
}
</style>
