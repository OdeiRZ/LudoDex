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
    light", so the icon shown is the sun, not the moon). Wrapped in a
    Transition so switching reads as sun<->moon actually swapping places
    (rotate + cross-fade) instead of one icon just replacing the other
    mid-frame. -->
    <Transition name="theme-icon" mode="out-in">
      <svg
        v-if="theme === 'dark'"
        key="sun"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        aria-hidden="true"
      >
        <circle cx="12" cy="12" r="4" fill="currentColor" stroke="none" />
        <path
          stroke-linecap="round"
          d="M12 2v2.5M12 19.5V22M4.22 4.22l1.77 1.77M18.01 18.01l1.77 1.77M2 12h2.5M19.5 12H22M4.22 19.78l1.77-1.77M18.01 5.99l1.77-1.77"
        />
      </svg>
      <svg v-else key="moon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
        <path d="M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9Z" />
      </svg>
    </Transition>
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
  transition:
    background-color 0.15s ease,
    transform 0.12s ease;
}

.theme-toggle:hover,
.theme-toggle:active {
  background: var(--color-surface-hover);
}

.theme-toggle:active {
  transform: scale(0.9);
}

.theme-toggle svg {
  width: 18px;
  height: 18px;
}

.theme-icon-enter-active,
.theme-icon-leave-active {
  transition:
    opacity 0.2s ease,
    transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.theme-icon-enter-from {
  opacity: 0;
  transform: rotate(-90deg) scale(0.4);
}

.theme-icon-leave-to {
  opacity: 0;
  transform: rotate(90deg) scale(0.4);
}

@media (prefers-reduced-motion: reduce) {
  .theme-toggle {
    transition: none;
  }

  .theme-toggle:active {
    transform: none;
  }

  .theme-icon-enter-active,
  .theme-icon-leave-active {
    transition: opacity 0.1s ease;
  }

  .theme-icon-enter-from,
  .theme-icon-leave-to {
    transform: none;
  }
}
</style>
