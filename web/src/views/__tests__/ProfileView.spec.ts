import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ProfileView from '@/views/ProfileView.vue'
import { useAuthStore } from '@/stores/auth'
import { i18n } from '@/i18n'

const user = {
  id: 1,
  name: 'Odei',
  email: 'odei@example.com',
  bgg_username: 'odei_bgg',
  avatar_url: null,
}

// Pre-seeding auth.user keeps onMounted from calling fetchCurrentUser()
// through the real apiClient.
function mountProfile() {
  setActivePinia(createPinia())
  const store = useAuthStore()
  store.user = { ...user }

  const wrapper = mount(ProfileView, { global: { plugins: [i18n] } })

  return { wrapper, store }
}

describe('ProfileView personal data form', () => {
  it('prefills the form from the current user', async () => {
    const { wrapper } = mountProfile()
    await flushPromises()

    expect((wrapper.find('#name').element as HTMLInputElement).value).toBe('Odei')
    expect((wrapper.find('#email').element as HTMLInputElement).value).toBe('odei@example.com')
    expect((wrapper.find('#bgg_username').element as HTMLInputElement).value).toBe('odei_bgg')
  })

  it('saves the profile and shows a success message', async () => {
    const { wrapper, store } = mountProfile()
    await flushPromises()
    vi.spyOn(store, 'updateProfile').mockResolvedValue()

    await wrapper.find('#name').setValue('Nuevo nombre')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(store.updateProfile).toHaveBeenCalledWith({
      name: 'Nuevo nombre',
      email: 'odei@example.com',
      bgg_username: 'odei_bgg',
    })
    expect(wrapper.find('[role="status"]').text()).toBe('Cambios guardados.')
  })

  it('shows field errors from a 422 response instead of the success message', async () => {
    const { wrapper, store } = mountProfile()
    await flushPromises()
    vi.spyOn(store, 'updateProfile').mockRejectedValue({
      isAxiosError: true,
      response: { status: 422, data: { errors: { email: ['El email ya está en uso.'] } } },
    })

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('El email ya está en uso.')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('shows a generic error for a non-validation failure', async () => {
    const { wrapper, store } = mountProfile()
    await flushPromises()
    vi.spyOn(store, 'updateProfile').mockRejectedValue(new Error('network error'))

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('No se han podido guardar los cambios.')
  })
})

describe('ProfileView change password form', () => {
  function passwordForm(wrapper: ReturnType<typeof mountProfile>['wrapper']) {
    return wrapper.findAll('form')[1]!
  }

  it('changes the password, shows a success message and clears the fields', async () => {
    const { wrapper, store } = mountProfile()
    await flushPromises()
    // updatePassword receives the same reactive object the view clears right
    // after awaiting it, so a plain mock would only ever see the
    // already-cleared values - snapshot a shallow copy at call time instead.
    let receivedPayload: unknown
    vi.spyOn(store, 'updatePassword').mockImplementation((payload) => {
      receivedPayload = { ...payload }
      return Promise.resolve()
    })

    await wrapper.find('#current_password').setValue('old-password')
    await wrapper.find('#new_password').setValue('new-password')
    await wrapper.find('#new_password_confirmation').setValue('new-password')
    await passwordForm(wrapper).trigger('submit')
    await flushPromises()

    expect(receivedPayload).toEqual({
      current_password: 'old-password',
      password: 'new-password',
      password_confirmation: 'new-password',
    })
    expect(wrapper.text()).toContain('Contraseña actualizada.')
    expect((wrapper.find('#current_password').element as HTMLInputElement).value).toBe('')
    expect((wrapper.find('#new_password').element as HTMLInputElement).value).toBe('')
  })

  it('shows a field error when the current password is wrong', async () => {
    const { wrapper, store } = mountProfile()
    await flushPromises()
    vi.spyOn(store, 'updatePassword').mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: { errors: { current_password: ['La contraseña actual no es correcta.'] } },
      },
    })

    await wrapper.find('#current_password').setValue('wrong')
    await wrapper.find('#new_password').setValue('new-password')
    await wrapper.find('#new_password_confirmation').setValue('new-password')
    await passwordForm(wrapper).trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('La contraseña actual no es correcta.')
  })

  it('does not clear the password fields when the request fails', async () => {
    const { wrapper, store } = mountProfile()
    await flushPromises()
    vi.spyOn(store, 'updatePassword').mockRejectedValue(new Error('network error'))

    await wrapper.find('#current_password').setValue('old-password')
    await wrapper.find('#new_password').setValue('new-password')
    await wrapper.find('#new_password_confirmation').setValue('new-password')
    await passwordForm(wrapper).trigger('submit')
    await flushPromises()

    expect((wrapper.find('#current_password').element as HTMLInputElement).value).toBe('old-password')
  })
})
