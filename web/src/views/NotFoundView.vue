<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
</script>

<template>
  <div class="not-found">
    <h1>{{ $t('notFound.title') }}</h1>
    <p>{{ $t('notFound.message') }}</p>
    <RouterLink :to="{ name: auth.isAuthenticated ? 'dashboard' : 'login' }" class="btn btn-primary">
      {{ $t('notFound.backLink') }}
    </RouterLink>
  </div>
</template>

<style scoped>
.not-found {
  max-width: 380px;
  margin: var(--space-8) auto 0;
  text-align: center;
}

h1 {
  margin-bottom: var(--space-2);
}

p {
  color: var(--color-text-muted);
  margin-bottom: var(--space-4);
}
</style>
