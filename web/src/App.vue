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
    <RouterLink :to="{ name: 'dashboard' }" class="brand">🎲 <span class="brand-name">LudoDex</span></RouterLink>

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
          <span class="user-name-text">{{ auth.user.name }}</span>
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

/* Below this the header's own wrapping (brand/nav on one line, session
below) starts looking cramped - dropping the text next to the dice
keeps the brand mark itself without needing the full wordmark's width,
freeing up a bit more room for primary-nav next to it. */
@media (max-width: 475px) {
  .brand-name {
    display: none;
  }
}

/* At a real 366px phone (the narrowest reported so far) even just the
dice by itself is one more thing competing for room in an already tight
header - the nav links matter more here, and still link back to the
collection the same as the dice would have. */
@media (max-width: 366px) {
  .brand {
    display: none;
  }
}

/* flex: 1 lets primary-nav claim whatever space is left between brand
and session on its row (whichever row that ends up being, given
header's own wrapping) - justify-content: center then centers the
links within that space instead of leaving them flush against
brand's edge, without touching how/when the row wraps. */
.primary-nav {
  display: flex;
  flex: 1;
  justify-content: center;
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
  white-space: nowrap;
}

/* .session itself doesn't wrap (see its own comment above) - without
this, once it stopped fitting on one line even on its own row, the name
and "Cerrar sesión" wrapped mid-word inside their own boxes instead of
the row growing or something giving way, reading as broken rather than
just tight. Dropping the name text first frees up enough room that
nothing needs to wrap; the avatar (still a link to the profile) and the
name itself sitting in Mi perfil are enough to identify the account
without repeating it here. */
@media (max-width: 400px) {
  .user-name-text {
    display: none;
  }

  .session .btn {
    white-space: nowrap;
  }
}

footer {
  display: flex;
  justify-content: center;
  padding: var(--space-6) 0 var(--space-4);
  margin-top: var(--space-6);
  border-top: 1px solid var(--color-border);
}
</style>
