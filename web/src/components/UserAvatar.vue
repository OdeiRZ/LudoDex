<script setup lang="ts">
import { ref, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    name: string
    avatarUrl?: string | null
    size?: number
  }>(),
  { avatarUrl: null, size: 32 },
)

// Falls back to an initial if the image 404s (e.g. a BGG avatar URL that
// stopped existing) instead of showing a broken-image icon.
const showFallback = ref(false)

watch(
  () => props.avatarUrl,
  () => {
    showFallback.value = false
  },
)

function initial(name: string): string {
  return name.trim().charAt(0).toUpperCase() || '?'
}
</script>

<template>
  <img
    v-if="avatarUrl && !showFallback"
    :src="avatarUrl"
    :alt="name"
    class="avatar"
    :style="{ width: `${size}px`, height: `${size}px` }"
    @error="showFallback = true"
  />
  <span
    v-else
    class="avatar avatar-fallback"
    :style="{ width: `${size}px`, height: `${size}px`, fontSize: `${size * 0.5}px` }"
  >
    {{ initial(name) }}
  </span>
</template>

<style scoped>
.avatar {
  border-radius: var(--radius-pill);
  object-fit: cover;
  flex-shrink: 0;
}

.avatar-fallback {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--color-primary);
  color: #f8fafc;
  font-weight: 600;
  line-height: 1;
}
</style>
