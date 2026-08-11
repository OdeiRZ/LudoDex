<script setup lang="ts">
import { ref, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    imageUrl?: string | null
    compact?: boolean
  }>(),
  { imageUrl: null, compact: false },
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
  <div class="game-cover" :class="{ compact }">
    <img
      v-if="imageUrl && !showFallback"
      :src="imageUrl"
      alt=""
      class="cover-image"
      @error="showFallback = true"
    />
    <div v-else class="cover-fallback">
      <img src="/icons/icon-192.png" alt="" class="cover-fallback-icon" />
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
  min-height: 220px;
  border-radius: var(--radius);
  overflow: hidden;
  background: var(--color-surface-hover);
  box-shadow: var(--shadow-card);
}

.game-cover.compact {
  min-height: 140px;
}

.cover-image {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
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
contrast rather than the theme's card colors. */
.cover-scrim {
  position: relative;
  z-index: 1;
  padding: var(--space-3);
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  color: #fff;
  background: linear-gradient(
    to top,
    rgba(15, 23, 42, 0.85) 0%,
    rgba(15, 23, 42, 0.55) 65%,
    transparent 100%
  );
}

.cover-scrim :deep(h2) {
  color: #fff;
}
</style>
