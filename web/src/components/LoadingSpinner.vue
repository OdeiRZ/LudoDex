<script setup lang="ts">
withDefaults(defineProps<{ size?: number }>(), { size: 32 })
</script>

<template>
  <svg
    class="loading-spinner"
    :style="{ width: `${size}px`, height: `${size}px` }"
    viewBox="0 0 24 24"
    fill="none"
    role="status"
    aria-label="Cargando"
  >
    <rect x="3" y="3" width="18" height="18" rx="4" fill="currentColor" opacity="0.15" />
    <rect x="3" y="3" width="18" height="18" rx="4" stroke="currentColor" stroke-width="1.5" />
    <circle cx="8" cy="8" r="1.6" fill="currentColor" />
    <circle cx="16" cy="8" r="1.6" fill="currentColor" />
    <circle cx="12" cy="12" r="1.6" fill="currentColor" />
    <circle cx="8" cy="16" r="1.6" fill="currentColor" />
    <circle cx="16" cy="16" r="1.6" fill="currentColor" />
  </svg>
</template>

<style scoped>
.loading-spinner {
  animation: spin 0.9s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .loading-spinner {
    animation: none;
  }
}
</style>
