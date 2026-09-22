<script setup lang="ts">
import type { Density } from '@/composables/useCollectionDensity'
import { useI18n } from 'vue-i18n'

defineProps<{ density: Density }>()
defineEmits<{ toggle: [] }>()

const { t } = useI18n()
</script>

<template>
  <button
    type="button"
    class="density-toggle"
    :aria-label="
      density === 'compact' ? t('common.densityToComfortable') : t('common.densityToCompact')
    "
    :title="density === 'compact' ? t('common.densityToComfortable') : t('common.densityToCompact')"
    @click="$emit('toggle')"
  >
    <!-- Icon shows the mode a click leads to, not the current one - same
    convention as ThemeToggle. Wrapped in a Transition so switching
    actually reads as a swap (rotate + cross-fade) instead of the icon
    just being replaced mid-frame. -->
    <Transition name="density-icon" mode="out-in">
      <svg
        v-if="density === 'compact'"
        key="comfortable"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        aria-hidden="true"
      >
        <rect x="3" y="4" width="18" height="6" rx="1" />
        <rect x="3" y="14" width="18" height="6" rx="1" />
      </svg>
      <svg
        v-else
        key="compact"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        aria-hidden="true"
      >
        <line x1="3" y1="5" x2="21" y2="5" />
        <line x1="3" y1="10" x2="21" y2="10" />
        <line x1="3" y1="15" x2="21" y2="15" />
        <line x1="3" y1="20" x2="21" y2="20" />
      </svg>
    </Transition>
  </button>
</template>

<style scoped>
.density-toggle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  padding: 0;
  flex-shrink: 0;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text);
  transition:
    background-color 0.15s ease,
    transform 0.12s ease;
}

.density-toggle:hover,
.density-toggle:active {
  background: var(--color-surface-hover);
}

.density-toggle:active {
  transform: scale(0.92);
}

.density-toggle svg {
  width: 18px;
  height: 18px;
}

.density-icon-enter-active,
.density-icon-leave-active {
  transition:
    opacity 0.15s ease,
    transform 0.15s ease;
}

.density-icon-enter-from {
  opacity: 0;
  transform: rotate(-45deg) scale(0.6);
}

.density-icon-leave-to {
  opacity: 0;
  transform: rotate(45deg) scale(0.6);
}

@media (prefers-reduced-motion: reduce) {
  .density-toggle {
    transition: none;
  }

  .density-toggle:active {
    transform: none;
  }

  .density-icon-enter-active,
  .density-icon-leave-active {
    transition: opacity 0.1s ease;
  }

  .density-icon-enter-from,
  .density-icon-leave-to {
    transform: none;
  }
}
</style>
