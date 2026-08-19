import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import App from '@/App.vue'
import { useAuthStore } from '@/stores/auth'
import { i18n } from '@/i18n'

// Only the routes App.vue itself links to need to exist here - the routed
// view's own behaviour (data fetching, etc.) isn't what these tests cover.
function makeRouter(startPath: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'dashboard', component: { template: '<div>Dashboard</div>' } },
      { path: '/picker', name: 'picker', component: { template: '<div>Picker</div>' } },
      { path: '/plays', name: 'plays', component: { template: '<div>Plays</div>' } },
      { path: '/import', name: 'import-bgg', component: { template: '<div>Import</div>' } },
      { path: '/profile', name: 'profile', component: { template: '<div>Profile</div>' } },
      { path: '/login', name: 'login', component: { template: '<div>Login</div>' } },
      { path: '/register', name: 'register', component: { template: '<div>Register</div>' } },
    ],
  })
  router.push(startPath)
  return router
}

describe('App', () => {
  // The auth token persists in localStorage across page loads (that's the
  // whole point being tested here) - clear it so one test's session doesn't
  // leak into the next one's "not authenticated" state.
  beforeEach(() => {
    localStorage.clear()
  })

  it('restores the logged-in user on mount, regardless of which page it lands on', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.token = 'a-token'
    const fetchSpy = vi.spyOn(auth, 'fetchCurrentUser').mockResolvedValue()

    const router = makeRouter('/picker')
    await router.isReady()
    mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(fetchSpy).toHaveBeenCalled()
  })

  it('does not fetch the user when not authenticated', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    const fetchSpy = vi.spyOn(auth, 'fetchCurrentUser')

    const router = makeRouter('/login')
    await router.isReady()
    mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('does not re-fetch a user the store already has', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.token = 'a-token'
    auth.user = { id: 1, name: 'Odei', email: 'odei@example.com', bgg_username: null, avatar_url: null }
    const fetchSpy = vi.spyOn(auth, 'fetchCurrentUser')

    const router = makeRouter('/picker')
    await router.isReady()
    mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('shows the restored user name in the header once fetched', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.token = 'a-token'
    vi.spyOn(auth, 'fetchCurrentUser').mockImplementation(async () => {
      auth.user = { id: 1, name: 'Odei', email: 'odei@example.com', bgg_username: null, avatar_url: null }
    })

    const router = makeRouter('/picker')
    await router.isReady()
    const wrapper = mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(wrapper.find('.user-name').text()).toContain('Odei')
  })
})
