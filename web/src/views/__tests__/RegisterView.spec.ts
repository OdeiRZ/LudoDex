import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import RegisterView from '@/views/RegisterView.vue'
import { useAuthStore } from '@/stores/auth'
import { i18n } from '@/i18n'

function makeRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'dashboard', component: { template: '<div>Dashboard</div>' } },
      { path: '/register', name: 'register', component: RegisterView },
      { path: '/login', name: 'login', component: { template: '<div>Login</div>' } },
    ],
  })
  router.push('/register')
  return router
}

async function mountRegister() {
  setActivePinia(createPinia())
  const store = useAuthStore()
  const router = makeRouter()
  await router.isReady()

  const wrapper = mount(RegisterView, { global: { plugins: [router, i18n] } })

  return { wrapper, store, router }
}

async function fillForm(wrapper: Awaited<ReturnType<typeof mountRegister>>['wrapper']) {
  await wrapper.find('#name').setValue('Odei')
  await wrapper.find('#email').setValue('odei@example.com')
  await wrapper.find('#password').setValue('secreto123')
  await wrapper.find('#password_confirmation').setValue('secreto123')
}

describe('RegisterView', () => {
  it('registers and redirects to the dashboard on success', async () => {
    const { wrapper, store, router } = await mountRegister()
    vi.spyOn(store, 'register').mockResolvedValue()

    await fillForm(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(store.register).toHaveBeenCalledWith({
      name: 'Odei',
      email: 'odei@example.com',
      password: 'secreto123',
      password_confirmation: 'secreto123',
    })
    expect(router.currentRoute.value.name).toBe('dashboard')
  })

  it('shows field errors from a 422 response instead of redirecting', async () => {
    const { wrapper, store, router } = await mountRegister()
    vi.spyOn(store, 'register').mockRejectedValue({
      isAxiosError: true,
      response: { status: 422, data: { errors: { email: ['Ese email ya está en uso.'] } } },
    })

    await fillForm(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Ese email ya está en uso.')
    expect(router.currentRoute.value.name).toBe('register')
  })

  it('shows a generic error for a non-validation failure', async () => {
    const { wrapper, store } = await mountRegister()
    vi.spyOn(store, 'register').mockRejectedValue(new Error('network error'))

    await fillForm(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })
})
