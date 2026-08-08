<script setup lang="ts">
import { ref, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    imageUrl?: string | null
    size?: number
  }>(),
  { imageUrl: null, size: 64 },
)

// Falls back to the app's own die icon (same one used for the PWA install
// icon) instead of a broken-image glyph when there's no image_url yet or
// the URL 404s (e.g. a BGG image link that stopped existing).
const showFallback = ref(false)

watch(
  () => props.imageUrl,
  () => {
    showFallback.value = false
  },
)
</script>

<template>
  <img
    v-if="imageUrl && !showFallback"
    :src="imageUrl"
    alt=""
    class="thumbnail"
    :style="{ width: `${size}px`, height: `${size}px` }"
    @error="showFallback = true"
  />
  <img
    v-else
    src="/icons/icon-192.png"
    alt=""
    class="thumbnail thumbnail-fallback"
    :style="{ width: `${size}px`, height: `${size}px` }"
  />
</template>

<style scoped>
.thumbnail {
  object-fit: cover;
  border-radius: var(--radius-sm);
  border: 1px solid var(--color-border-strong);
  flex-shrink: 0;
}

.thumbnail-fallback {
  object-fit: contain;
  padding: 8px;
  background: var(--color-surface-hover);
}
</style>
