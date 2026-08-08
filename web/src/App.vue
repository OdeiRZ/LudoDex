<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function onLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <header>
    <RouterLink :to="{ name: 'dashboard' }" class="brand">LudoDex</RouterLink>

    <nav>
      <template v-if="auth.isAuthenticated">
        <span v-if="auth.user">{{ auth.user.name }}</span>
        <button type="button" @click="onLogout">Cerrar sesión</button>
      </template>
      <template v-else>
        <RouterLink :to="{ name: 'login' }">Entrar</RouterLink>
        <RouterLink :to="{ name: 'register' }">Crear cuenta</RouterLink>
      </template>
    </nav>
  </header>

  <main>
    <RouterView />
  </main>
</template>

<style scoped>
header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 1rem;
  border-bottom: 1px solid var(--color-border);
}

.brand {
  font-weight: bold;
  font-size: 1.25rem;
}

nav {
  display: flex;
  align-items: center;
  gap: 1rem;
}
</style>
