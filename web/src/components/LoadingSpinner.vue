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
    :aria-label="$t('common.loadingAriaLabel')"
  >
    <path
      d="M12,3 L4.2,7.5 L12,12 L19.8,7.5 Z"
      fill="currentColor"
      opacity="0.25"
      stroke="currentColor"
      stroke-width="1.2"
      stroke-linejoin="round"
    />
    <path
      d="M4.2,7.5 L4.2,16.5 L12,21 L12,12 Z"
      fill="currentColor"
      opacity="0.15"
      stroke="currentColor"
      stroke-width="1.2"
      stroke-linejoin="round"
    />
    <path
      d="M19.8,7.5 L12,12 L12,21 L19.8,16.5 Z"
      fill="currentColor"
      opacity="0.08"
      stroke="currentColor"
      stroke-width="1.2"
      stroke-linejoin="round"
    />
    <circle cx="12" cy="7.5" r="1.1" fill="currentColor" />
    <circle cx="7.2" cy="12.2" r="1.1" fill="currentColor" />
    <circle cx="9.4" cy="17.2" r="1.1" fill="currentColor" />
    <circle cx="16.8" cy="12.2" r="1.1" fill="currentColor" />
    <circle cx="14.6" cy="17.2" r="1.1" fill="currentColor" />
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
