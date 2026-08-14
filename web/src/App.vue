<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import UserAvatar from '@/components/UserAvatar.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import LanguageToggle from '@/components/LanguageToggle.vue'
import ToastNotification from '@/components/ToastNotification.vue'
import PoweredByBgg from '@/components/PoweredByBgg.vue'

const auth = useAuthStore()
const router = useRouter()

async function onLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}

// A stored token survives a reload, but the user object it belongs to
// doesn't - without this, the header's name/avatar only ever appeared if
// the session happened to pass through a view that fetched it itself
// (previously only the dashboard did), staying blank on a reload/deep
// link landing anywhere else. Lives here, at the root, so it runs once
// regardless of which page that turns out to be.
onMounted(() => {
  if (auth.isAuthenticated && !auth.user) {
    auth.fetchCurrentUser()
  }
})
</script>

<template>
  <header>
    <RouterLink :to="{ name: 'dashboard' }" class="brand">🎲 LudoDex</RouterLink>

    <nav v-if="auth.isAuthenticated" class="primary-nav">
      <RouterLink :to="{ name: 'dashboard' }">{{ $t('nav.collection') }}</RouterLink>
      <RouterLink :to="{ name: 'picker' }">{{ $t('nav.picker') }}</RouterLink>
      <RouterLink :to="{ name: 'import-bgg' }">{{ $t('nav.importBgg') }}</RouterLink>
    </nav>

    <div class="session">
      <LanguageToggle />
      <ThemeToggle />
      <template v-if="auth.isAuthenticated">
        <RouterLink v-if="auth.user" :to="{ name: 'profile' }" class="user-name">
          <UserAvatar :name="auth.user.name" :avatar-url="auth.user.avatar_url" :size="24" />
          {{ auth.user.name }}
        </RouterLink>
        <button type="button" class="btn" @click="onLogout">{{ $t('nav.logout') }}</button>
      </template>
      <template v-else>
        <RouterLink :to="{ name: 'login' }" class="btn">{{ $t('nav.login') }}</RouterLink>
        <RouterLink :to="{ name: 'register' }" class="btn btn-primary">{{ $t('nav.register') }}</RouterLink>
      </template>
    </div>
  </header>

  <main>
    <RouterView />
  </main>

  <footer>
    <PoweredByBgg />
  </footer>

  <ToastNotification />
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

/* margin-left: auto (rather than relying on the header's own
justify-content: space-between alone) is what keeps this flush to the
right edge once it no longer fits next to brand/primary-nav and wraps
onto its own line - space-between alone left a lone wrapped item at the
row's start (the left edge) instead, since there's nothing else on that
line to distribute space against. */
.session {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin-left: auto;
}

.user-name {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

footer {
  display: flex;
  justify-content: center;
  padding: var(--space-6) 0 var(--space-4);
  margin-top: var(--space-6);
  border-top: 1px solid var(--color-border);
}
</style>
