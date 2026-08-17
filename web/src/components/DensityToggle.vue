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
    :aria-label="density === 'compact' ? t('common.densityToComfortable') : t('common.densityToCompact')"
    :title="density === 'compact' ? t('common.densityToComfortable') : t('common.densityToCompact')"
    @click="$emit('toggle')"
  >
    <!-- Icon shows the mode a click leads to, not the current one - same
    convention as ThemeToggle. -->
    <svg v-if="density === 'compact'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <rect x="3" y="4" width="18" height="6" rx="1" />
      <rect x="3" y="14" width="18" height="6" rx="1" />
    </svg>
    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <line x1="3" y1="5" x2="21" y2="5" />
      <line x1="3" y1="10" x2="21" y2="10" />
      <line x1="3" y1="15" x2="21" y2="15" />
      <line x1="3" y1="20" x2="21" y2="20" />
    </svg>
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
}

.density-toggle:hover {
  background: var(--color-surface-hover);
}

.density-toggle svg {
  width: 18px;
  height: 18px;
}
</style>
