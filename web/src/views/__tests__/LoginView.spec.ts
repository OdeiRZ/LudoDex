import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import LoginView from '@/views/LoginView.vue'
import { useAuthStore } from '@/stores/auth'
import { i18n } from '@/i18n'

function makeRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'dashboard', component: { template: '<div>Dashboard</div>' } },
      { path: '/login', name: 'login', component: LoginView },
      { path: '/register', name: 'register', component: { template: '<div>Register</div>' } },
      { path: '/forgot-password', name: 'forgot-password', component: { template: '<div>Forgot</div>' } },
    ],
  })
  router.push('/login')
  return router
}

async function mountLogin() {
  setActivePinia(createPinia())
  const store = useAuthStore()
  const router = makeRouter()
  await router.isReady()

  const wrapper = mount(LoginView, { global: { plugins: [router, i18n] } })

  return { wrapper, store, router }
}

describe('LoginView', () => {
  it('logs in and redirects to the dashboard on success', async () => {
    const { wrapper, store, router } = await mountLogin()
    vi.spyOn(store, 'login').mockResolvedValue()

    await wrapper.find('#email').setValue('odei@example.com')
    await wrapper.find('#password').setValue('secreto123')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(store.login).toHaveBeenCalledWith({ email: 'odei@example.com', password: 'secreto123' })
    expect(router.currentRoute.value.name).toBe('dashboard')
  })

  it('shows an error message and stays on the page when login fails', async () => {
    const { wrapper, store, router } = await mountLogin()
    vi.spyOn(store, 'login').mockRejectedValue(new Error('invalid credentials'))

    await wrapper.find('#email').setValue('odei@example.com')
    await wrapper.find('#password').setValue('wrong')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(router.currentRoute.value.name).toBe('login')
  })

  it('links to the forgot-password page', async () => {
    const { wrapper } = await mountLogin()

    const link = wrapper.get('a.forgot-link')
    expect(link.attributes('href')).toBe('/forgot-password')
  })
})
