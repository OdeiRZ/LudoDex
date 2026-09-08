import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import App from '@/App.vue'
import { useAuthStore } from '@/stores/auth'
import { useFriendsStore } from '@/stores/friends'
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
      { path: '/friends', name: 'friends', component: { template: '<div>Friends</div>' } },
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
    vi.spyOn(useFriendsStore(), 'fetchAll').mockResolvedValue()

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

  it('shows a reminder to verify the email for an authenticated user who has not verified yet', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.token = 'a-token'
    auth.user = {
      id: 1,
      name: 'Odei',
      email: 'odei@example.com',
      bgg_username: null,
      avatar_url: null,
      email_verified_at: null,
      discoverable: false,
    }
    vi.spyOn(useFriendsStore(), 'fetchAll').mockResolvedValue()

    const router = makeRouter('/')
    await router.isReady()
    const wrapper = mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(wrapper.find('.verify-banner').exists()).toBe(true)
    expect(wrapper.find('.verify-banner').text()).toContain('odei@example.com')
  })

  it('hides the verification reminder once the email is verified', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.token = 'a-token'
    auth.user = {
      id: 1,
      name: 'Odei',
      email: 'odei@example.com',
      bgg_username: null,
      avatar_url: null,
      email_verified_at: '2026-09-08T00:00:00.000000Z',
      discoverable: false,
    }
    vi.spyOn(useFriendsStore(), 'fetchAll').mockResolvedValue()

    const router = makeRouter('/')
    await router.isReady()
    const wrapper = mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(wrapper.find('.verify-banner').exists()).toBe(false)
  })

  it('resends the verification email and shows the backend message', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.token = 'a-token'
    auth.user = {
      id: 1,
      name: 'Odei',
      email: 'odei@example.com',
      bgg_username: null,
      avatar_url: null,
      email_verified_at: null,
      discoverable: false,
    }
    const resendSpy = vi
      .spyOn(auth, 'resendVerificationEmail')
      .mockResolvedValue('Te hemos enviado un nuevo enlace de verificación.')
    vi.spyOn(useFriendsStore(), 'fetchAll').mockResolvedValue()

    const router = makeRouter('/')
    await router.isReady()
    const wrapper = mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    await wrapper.find('.verify-banner button').trigger('click')
    await flushPromises()

    expect(resendSpy).toHaveBeenCalled()
    expect(wrapper.find('.verify-banner').text()).toContain(
      'Te hemos enviado un nuevo enlace de verificación.',
    )
  })

  it('does not re-fetch a user the store already has', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.token = 'a-token'
    auth.user = {
      id: 1,
      name: 'Odei',
      email: 'odei@example.com',
      bgg_username: null,
      avatar_url: null,
      email_verified_at: null,
      discoverable: false,
    }
    const fetchSpy = vi.spyOn(auth, 'fetchCurrentUser')
    vi.spyOn(useFriendsStore(), 'fetchAll').mockResolvedValue()

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
      auth.user = {
        id: 1,
        name: 'Odei',
        email: 'odei@example.com',
        bgg_username: null,
        avatar_url: null,
        email_verified_at: null,
        discoverable: false,
      }
    })
    vi.spyOn(useFriendsStore(), 'fetchAll').mockResolvedValue()

    const router = makeRouter('/picker')
    await router.isReady()
    const wrapper = mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(wrapper.find('.user-name').text()).toContain('Odei')
  })

  it('shows a friends nav link (in both primary and mobile nav) when authenticated', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.token = 'a-token'
    auth.user = {
      id: 1,
      name: 'Odei',
      email: 'odei@example.com',
      bgg_username: null,
      avatar_url: null,
      email_verified_at: null,
      discoverable: false,
    }
    vi.spyOn(useFriendsStore(), 'fetchAll').mockResolvedValue()

    const router = makeRouter('/')
    await router.isReady()
    const wrapper = mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(wrapper.find('.primary-nav a[href="/friends"]').exists()).toBe(true)

    // The mobile nav is only rendered once the burger menu is open.
    await wrapper.find('.hamburger-btn').trigger('click')
    await flushPromises()
    expect(wrapper.find('.mobile-nav a[href="/friends"]').exists()).toBe(true)
  })

  it('does not show the friends nav link when not authenticated', async () => {
    setActivePinia(createPinia())

    const router = makeRouter('/login')
    await router.isReady()
    const wrapper = mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(wrapper.find('.primary-nav').exists()).toBe(false)
  })

  it('loads incoming friend requests on mount when authenticated', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.token = 'a-token'
    const friends = useFriendsStore()
    const fetchSpy = vi.spyOn(friends, 'fetchAll').mockResolvedValue()

    const router = makeRouter('/')
    await router.isReady()
    mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(fetchSpy).toHaveBeenCalled()
  })

  it('does not load friend requests when not authenticated', async () => {
    setActivePinia(createPinia())
    const friends = useFriendsStore()
    const fetchSpy = vi.spyOn(friends, 'fetchAll')

    const router = makeRouter('/login')
    await router.isReady()
    mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(fetchSpy).not.toHaveBeenCalled()
  })

  it('shows a notification dot on the friends nav link when there are incoming requests', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.token = 'a-token'
    const friends = useFriendsStore()
    vi.spyOn(friends, 'fetchAll').mockImplementation(async () => {
      friends.incomingRequests = [
        { id: 1, user: { id: 2, name: 'Friend One', bgg_username: null, avatar_url: null } },
      ]
    })

    const router = makeRouter('/')
    await router.isReady()
    const wrapper = mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(wrapper.find('.primary-nav .nav-badge').exists()).toBe(true)

    await wrapper.find('.hamburger-btn').trigger('click')
    await flushPromises()
    expect(wrapper.find('.mobile-nav .nav-badge').exists()).toBe(true)
  })

  it('does not show a notification dot when there are no incoming requests', async () => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.token = 'a-token'
    const friends = useFriendsStore()
    vi.spyOn(friends, 'fetchAll').mockResolvedValue()

    const router = makeRouter('/')
    await router.isReady()
    const wrapper = mount(App, { global: { plugins: [router, i18n] } })
    await flushPromises()

    expect(wrapper.find('.nav-badge').exists()).toBe(false)
  })
})
