<script setup lang="ts">
import { useTheme } from '@/composables/useTheme'

const { theme, toggle } = useTheme()
</script>

<template>
  <button
    type="button"
    class="theme-toggle"
    :aria-label="theme === 'dark' ? $t('theme.toLight') : $t('theme.toDark')"
    :title="theme === 'dark' ? $t('theme.toLight') : $t('theme.toDark')"
    @click="toggle"
  >
    <!-- Icon shows the mode a click leads to, not the current one - matches
    the aria-label/title above (e.g. in dark mode the label reads "switch to
    light", so the icon shown is the sun, not the moon). -->
    <svg v-if="theme === 'dark'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <circle cx="12" cy="12" r="4" fill="currentColor" stroke="none" />
      <path
        stroke-linecap="round"
        d="M12 2v2.5M12 19.5V22M4.22 4.22l1.77 1.77M18.01 18.01l1.77 1.77M2 12h2.5M19.5 12H22M4.22 19.78l1.77-1.77M18.01 5.99l1.77-1.77"
      />
    </svg>
    <svg v-else viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path
        d="M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9Z"
      />
    </svg>
  </button>
</template>

<style scoped>
.theme-toggle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  padding: 0;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text);
}

.theme-toggle:hover {
  background: var(--color-surface-hover);
}

.theme-toggle svg {
  width: 18px;
  height: 18px;
}
</style>
