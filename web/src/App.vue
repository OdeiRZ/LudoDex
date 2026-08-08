<script setup lang="ts">
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import UserAvatar from '@/components/UserAvatar.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'

const auth = useAuthStore()
const router = useRouter()

async function onLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <header>
    <RouterLink :to="{ name: 'dashboard' }" class="brand">🎲 LudoDex</RouterLink>

    <nav v-if="auth.isAuthenticated" class="primary-nav">
      <RouterLink :to="{ name: 'dashboard' }">Colección</RouterLink>
      <RouterLink :to="{ name: 'picker' }">¿A qué jugamos?</RouterLink>
      <RouterLink :to="{ name: 'import-bgg' }">Importar BGG</RouterLink>
    </nav>

    <div class="session">
      <ThemeToggle />
      <template v-if="auth.isAuthenticated">
        <RouterLink v-if="auth.user" :to="{ name: 'profile' }" class="user-name">
          <UserAvatar :name="auth.user.name" :avatar-url="auth.user.avatar_url" :size="24" />
          {{ auth.user.name }}
        </RouterLink>
        <button type="button" class="btn" @click="onLogout">Cerrar sesión</button>
      </template>
      <template v-else>
        <RouterLink :to="{ name: 'login' }" class="btn">Entrar</RouterLink>
        <RouterLink :to="{ name: 'register' }" class="btn btn-primary">Crear cuenta</RouterLink>
      </template>
    </div>
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
  flex-wrap: wrap;
  gap: var(--space-4);
  padding-bottom: var(--space-4);
  margin-bottom: var(--space-6);
  border-bottom: 1px solid var(--color-border);
}

.brand {
  font-weight: 700;
  font-size: 1.25rem;
  color: var(--color-heading);
}

.brand:hover {
  text-decoration: none;
}

.primary-nav {
  display: flex;
  gap: var(--space-4);
  font-size: 0.9rem;
}

.primary-nav a {
  color: var(--color-text);
  font-weight: 500;
}

.primary-nav a.router-link-exact-active {
  color: var(--color-primary-hover);
}

.session {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.user-name {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--color-text-muted);
  font-size: 0.9rem;
}
</style>
