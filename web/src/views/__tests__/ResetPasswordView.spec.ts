import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import ResetPasswordView from '@/views/ResetPasswordView.vue'
import { useAuthStore } from '@/stores/auth'
import { i18n } from '@/i18n'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: { template: '<div>Login</div>' } },
      { path: '/reset-password', name: 'reset-password', component: ResetPasswordView },
    ],
  })
}

// Mirrors EditGameView's own spec: ResetPasswordView reads token/email via
// useRoute().query rather than props, so a real router already navigated to
// the target URL (with its query string) is required - no <RouterView> needed.
async function mountReset(path: string) {
  setActivePinia(createPinia())
  const store = useAuthStore()

  const router = makeRouter()
  router.push(path)
  await router.isReady()

  const wrapper = mount(ResetPasswordView, { global: { plugins: [router, i18n] } })

  return { wrapper, store, router }
}

async function submitNewPassword(wrapper: Awaited<ReturnType<typeof mountReset>>['wrapper']) {
  await wrapper.find('#password').setValue('new-password')
  await wrapper.find('#password_confirmation').setValue('new-password')
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

describe('ResetPasswordView', () => {
  it('submits the token and email read from the query string, plus the new password', async () => {
    const { wrapper, store } = await mountReset(
      '/reset-password?token=abc123&email=odei%40example.com',
    )
    vi.spyOn(store, 'resetPassword').mockResolvedValue('Tu contraseña se ha restablecido.')

    await submitNewPassword(wrapper)

    expect(store.resetPassword).toHaveBeenCalledWith({
      token: 'abc123',
      email: 'odei@example.com',
      password: 'new-password',
      password_confirmation: 'new-password',
    })
  })

  it('shows the success message and a link to log in', async () => {
    const { wrapper, store } = await mountReset('/reset-password?token=abc123&email=odei%40example.com')
    vi.spyOn(store, 'resetPassword').mockResolvedValue('Tu contraseña se ha restablecido.')

    await submitNewPassword(wrapper)

    expect(wrapper.find('[role="status"]').text()).toContain('Tu contraseña se ha restablecido.')
    expect(wrapper.find('a').attributes('href')).toBe('/login')
  })

  it('shows field errors from a 422 response, e.g. an invalid or expired token', async () => {
    const { wrapper, store } = await mountReset('/reset-password?token=expired&email=odei%40example.com')
    vi.spyOn(store, 'resetPassword').mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: { errors: { email: ['Ese enlace para restablecer la contraseña no es válido.'] } },
      },
    })

    await submitNewPassword(wrapper)

    expect(wrapper.text()).toContain('Ese enlace para restablecer la contraseña no es válido.')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('shows a generic error for a non-validation failure', async () => {
    const { wrapper, store } = await mountReset('/reset-password?token=abc123&email=odei%40example.com')
    vi.spyOn(store, 'resetPassword').mockRejectedValue(new Error('network error'))

    await submitNewPassword(wrapper)

    expect(wrapper.text()).toContain('No se ha podido restablecer la contraseña.')
  })
})
