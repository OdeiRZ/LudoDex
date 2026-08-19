<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import UserAvatar from '@/components/UserAvatar.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import LanguageToggle from '@/components/LanguageToggle.vue'
import ToastNotification from '@/components/ToastNotification.vue'
import PoweredByBgg from '@/components/PoweredByBgg.vue'
import ScrollToTopButton from '@/components/ScrollToTopButton.vue'

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

    <RouterLink
      v-if="auth.isAuthenticated && auth.user"
      :to="{ name: 'profile' }"
      class="mobile-avatar"
    >
      <UserAvatar :name="auth.user.name" :avatar-url="auth.user.avatar_url" :size="24" />
    </RouterLink>

    <nav v-if="auth.isAuthenticated" class="primary-nav">
      <RouterLink :to="{ name: 'dashboard' }">{{ $t('nav.collection') }}</RouterLink>
      <RouterLink :to="{ name: 'picker' }">{{ $t('nav.picker') }}</RouterLink>
      <RouterLink :to="{ name: 'plays' }">{{ $t('nav.plays') }}</RouterLink>
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
        <button type="button" class="btn logout-btn" :aria-label="$t('nav.logout')" @click="onLogout">
          <svg class="logout-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M16 17l5-5-5-5" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12H9" />
          </svg>
          <span class="logout-text">{{ $t('nav.logout') }}</span>
        </button>
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
  <ScrollToTopButton />
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

/* Hidden by default - only joins the dice on the header's left edge
below 702px, and stays there alone once the dice itself disappears at
618px (see both media queries below). */
.mobile-avatar {
  display: none;
}

/* Below this the header's own wrapping (brand/nav on one line, session
below) starts looking cramped - dropping the text next to the dice
keeps the brand mark itself without needing the full wordmark's width,
freeing up a bit more room for primary-nav next to it. Raised from
475px to 702px once a 4th nav link (Partidas) made the row need this
room sooner. */
@media (max-width: 702px) {
  .brand-name {
    display: none;
  }
}

/* flex: 1 lets primary-nav claim whatever space is left between brand
and session on its row (whichever row that ends up being, given
header's own wrapping) - justify-content: center then centers the
links within that space instead of leaving them flush against
brand's edge, without touching how/when the row wraps. */
/* flex-wrap is the safety net for whatever width isn't covered by the
breakpoints above: if the 4 links ever don't fit in one row, they
split into two centered rows instead of a single link's own text
wrapping mid-phrase inside its box. */
.primary-nav {
  display: flex;
  flex: 1;
  flex-wrap: wrap;
  justify-content: center;
  gap: var(--space-2) var(--space-4);
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

/* The avatar moves in to sit next to the dice (dice first, then avatar)
from the same width the wordmark itself drops (702px), rather than
waiting until the dice disappears too at 618px - both icons anchor the
header's left edge together for that whole range. Placed after
.user-name's own base rule (not before it) so this override actually
wins the cascade at equal specificity - the same class defined later
in source order always beats an earlier one when neither has more
specificity, media query or not. */
@media (max-width: 702px) {
  .mobile-avatar {
    display: flex;
  }

  /* Moved to .mobile-avatar on the header's left edge instead - showing
  it here too would duplicate the same avatar on both sides. */
  .user-name {
    display: none;
  }
}

/* At a real 366px phone (the narrowest reported so far) even just the
dice by itself is one more thing competing for room in an already tight
header - the nav links matter more here, and still link back to the
collection the same as the dice would have. Raised from 366px to 618px
once a 4th nav link (Partidas) made the row need this room sooner. */
@media (max-width: 618px) {
  .brand {
    display: none;
  }
}

/* Below 400px, .session itself doesn't wrap (see its own comment
above) - without this, once it stopped fitting on one line even on its
own row, the name and "Cerrar sesión" wrapped mid-word inside their own
boxes instead of the row growing or something giving way, reading as
broken rather than just tight.

Up to 872px, a different problem needs the same fix: primary-nav's own
flex: 1 lets it shrink rather than forcing the whole header to wrap
onto two rows, so at a real 768px tablet window "¿A qué jugamos?",
"Partidas" and "Importar BGG" wrap mid-phrase instead - dropping the
name here frees up enough of the row that primary-nav has room to lay
its links out on one line each again. Raised from 800px to 872px once
a 4th link (Partidas) made the un-wrapped nav row itself wider - 800px
left only ~14px of slack at that width, not real margin once a real
device's font rendering is in play.

Either way, the avatar (still a link to the profile) and the name
itself sitting in Mi perfil are enough to identify the account without
repeating it here. */
@media (max-width: 872px) {
  .user-name-text {
    display: none;
  }

  .session .btn {
    white-space: nowrap;
  }
}

.logout-icon {
  display: none;
  width: 18px;
  height: 18px;
}

/* Swaps "Cerrar sesión" for just its icon once the row gets tight enough
that every bit of width matters - narrower than 872px's own fix (which
only drops the user's name), so this only kicks in once that alone
isn't enough. */
@media (max-width: 786px) {
  .logout-text {
    display: none;
  }

  .logout-icon {
    display: block;
  }

  .logout-btn {
    padding-left: var(--space-2);
    padding-right: var(--space-2);
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
