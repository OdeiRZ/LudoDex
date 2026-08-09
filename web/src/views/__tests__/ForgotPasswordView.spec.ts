import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ForgotPasswordView from '@/views/ForgotPasswordView.vue'
import { useAuthStore } from '@/stores/auth'
import { i18n } from '@/i18n'

function mountForgot() {
  setActivePinia(createPinia())
  const store = useAuthStore()

  const wrapper = mount(ForgotPasswordView, {
    global: { stubs: { RouterLink: true }, plugins: [i18n] },
  })

  return { wrapper, store }
}

describe('ForgotPasswordView', () => {
  it('requests the reset link and shows the backend message on success', async () => {
    const { wrapper, store } = mountForgot()
    vi.spyOn(store, 'forgotPassword').mockResolvedValue(
      'Te hemos enviado por email el enlace para restablecer la contraseña.',
    )

    await wrapper.find('#email').setValue('odei@example.com')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(store.forgotPassword).toHaveBeenCalledWith('odei@example.com')
    expect(wrapper.find('[role="status"]').text()).toBe(
      'Te hemos enviado por email el enlace para restablecer la contraseña.',
    )
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('shows a field error when the email is not registered', async () => {
    const { wrapper, store } = mountForgot()
    vi.spyOn(store, 'forgotPassword').mockRejectedValue({
      isAxiosError: true,
      response: { status: 422, data: { errors: { email: ['No encontramos ningún usuario con ese email.'] } } },
    })

    await wrapper.find('#email').setValue('nadie@example.com')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('No encontramos ningún usuario con ese email.')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('shows a translated generic error for a non-validation failure', async () => {
    const { wrapper, store } = mountForgot()
    vi.spyOn(store, 'forgotPassword').mockRejectedValue(new Error('network error'))

    await wrapper.find('#email').setValue('odei@example.com')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('No se ha podido enviar el enlace.')
  })
})
