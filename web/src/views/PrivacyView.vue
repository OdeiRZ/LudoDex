<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const { t, tm } = useI18n()

// Cada apartado es un título y uno o varios párrafos en los ficheros de idioma
// (`privacy.sections.<clave>`): el texto es lo que importa, no la maquetación.
const SECTIONS = [
  'data',
  'use',
  'thirdParties',
  'google',
  'storage',
  'deletion',
  'contact',
] as const

function paragraphs(section: string): string[] {
  const count = (tm(`privacy.sections.${section}.paragraphs`) as unknown[]).length
  return Array.from({ length: count }, (_, i) => t(`privacy.sections.${section}.paragraphs[${i}]`))
}
</script>

<template>
  <article class="privacy">
    <h1>{{ $t('privacy.title') }}</h1>
    <p class="updated">{{ $t('privacy.updated') }}</p>
    <p>{{ $t('privacy.intro') }}</p>

    <section v-for="key in SECTIONS" :key="key">
      <h2>{{ $t(`privacy.sections.${key}.title`) }}</h2>
      <p v-for="(text, i) in paragraphs(key)" :key="i">{{ text }}</p>
    </section>

    <RouterLink
      :to="{ name: auth.isAuthenticated ? 'dashboard' : 'login' }"
      class="btn btn-primary"
    >
      {{ $t('notFound.backLink') }}
    </RouterLink>
  </article>
</template>

<style scoped>
.privacy {
  max-width: 720px;
  margin: var(--space-6) auto 0;
}

h1 {
  margin-bottom: var(--space-1);
}

h2 {
  margin: var(--space-5) 0 var(--space-2);
  font-size: 1.1rem;
}

p {
  margin-bottom: var(--space-3);
  line-height: 1.55;
}

.updated {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.btn {
  margin-top: var(--space-4);
}
</style>
