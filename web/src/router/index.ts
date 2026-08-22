import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    guestOnly?: boolean
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  // Without this, every navigation (including router.back()) landed at
  // the top of the new page regardless - reported directly: editing a
  // game from partway down a long collection, then saving, lost the
  // scroll position entirely. savedPosition is only ever populated for
  // a popstate-driven navigation (the real back/forward buttons, or
  // router.back()/forward()/go() - never a plain router.push()), so
  // this only restores scroll for those, falling back to the top for
  // an ordinary forward navigation to a new page.
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) {
      return savedPosition
    }

    return { top: 0 }
  },
  routes: [
    {
      path: '/',
      name: 'dashboard',
      component: () => import('@/views/DashboardView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/games/new',
      name: 'add-game',
      component: () => import('@/views/AddGameView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/games/:id/edit',
      name: 'edit-game',
      component: () => import('@/views/EditGameView.vue'),
      props: true,
      meta: { requiresAuth: true },
    },
    {
      path: '/picker',
      name: 'picker',
      component: () => import('@/views/PickerView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/plays',
      name: 'plays',
      component: () => import('@/views/PlaysView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/import',
      name: 'import-bgg',
      component: () => import('@/views/ImportBggView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/profile',
      name: 'profile',
      component: () => import('@/views/ProfileView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { guestOnly: true },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { guestOnly: true },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/views/ForgotPasswordView.vue'),
      meta: { guestOnly: true },
    },
    {
      // Deliberately not guestOnly: a reset link should work even if the
      // clicker is already logged in elsewhere (a second device, or an old
      // email opened out of habit), not silently bounce them away.
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('@/views/ResetPasswordView.vue'),
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
    },
  ],
})

router.beforeEach((to) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }
})

export default router
