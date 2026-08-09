<script setup lang="ts">
import { computed } from 'vue'
import { getLocale, setLocale } from '@/i18n'

// Not run through the translation strings on purpose: a language switcher's
// own label is conventionally shown in the language it switches TO
// ("English" on a Spanish page, "Español" on an English page), regardless
// of which language the reader currently understands.
const locale = computed(() => getLocale())

const target = computed(() =>
  locale.value === 'es' ? { code: 'EN', label: 'Switch to English' } : { code: 'ES', label: 'Cambiar a español' },
)

function toggle() {
  setLocale(locale.value === 'es' ? 'en' : 'es')
}
</script>

<template>
  <button
    type="button"
    class="language-toggle"
    :aria-label="target.label"
    :title="target.label"
    @click="toggle"
  >
    {{ target.code }}
  </button>
</template>

<style scoped>
.language-toggle {
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
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.language-toggle:hover {
  background: var(--color-surface-hover);
}
</style>
