import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import VerifyEmailView from '@/views/VerifyEmailView.vue'
import { useAuthStore } from '@/stores/auth'
import { i18n } from '@/i18n'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/verify-email', name: 'verify-email', component: VerifyEmailView },
      { path: '/login', name: 'login', component: { template: '<div>Login</div>' } },
      { path: '/', name: 'dashboard', component: { template: '<div>Dashboard</div>' } },
    ],
  })
}

// ok comes from the query string the API's redirect carries - the router
// has to already be on the target URL before mount(), same as clicking the
// real link from the verification email would land on it.
async function mountView(ok: string, authenticated = false) {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  if (authenticated) {
    auth.token = 'a-token'
  }
  const router = makeRouter()
  await router.push({ name: 'verify-email', query: { ok } })
  const wrapper = mount(VerifyEmailView, { global: { plugins: [router, i18n] } })

  return { wrapper, auth }
}

describe('VerifyEmailView', () => {
  it('shows a success message for ok=1', async () => {
    const { wrapper } = await mountView('1')

    expect(wrapper.find('.alert-success').exists()).toBe(true)
    expect(wrapper.find('.alert-error').exists()).toBe(false)
  })

  it('shows a failure message for ok=0', async () => {
    const { wrapper } = await mountView('0')

    expect(wrapper.find('.alert-error').exists()).toBe(true)
    expect(wrapper.find('.alert-success').exists()).toBe(false)
  })

  it('links back to the dashboard when already logged in', async () => {
    const { wrapper } = await mountView('1', true)

    const link = wrapper.find('a')
    expect(link.attributes('href')).toBe('/')
  })

  it('links back to login when not logged in', async () => {
    const { wrapper } = await mountView('0', false)

    const link = wrapper.find('a')
    expect(link.attributes('href')).toBe('/login')
  })
})
