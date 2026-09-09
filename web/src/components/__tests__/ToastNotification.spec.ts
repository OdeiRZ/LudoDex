import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ToastNotification from '@/components/ToastNotification.vue'
import { useToastStore } from '@/stores/toast'

function mountToast() {
  setActivePinia(createPinia())

  return { wrapper: mount(ToastNotification), toast: useToastStore() }
}

describe('ToastNotification', () => {
  it('renders nothing when there is no message', () => {
    const { wrapper } = mountToast()

    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('shows the current toast message from the store', async () => {
    const { wrapper, toast } = mountToast()

    toast.show('Cambios guardados.')
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[role="status"]').text()).toBe('Cambios guardados.')
  })

  it('renders a success toast with role="status" and the success styling', async () => {
    const { wrapper, toast } = mountToast()

    toast.show('Cambios guardados.', 'success')
    await wrapper.vm.$nextTick()

    const el = wrapper.find('.toast')
    expect(el.attributes('role')).toBe('status')
    expect(el.classes()).toContain('alert-success')
    expect(el.classes()).not.toContain('alert-error')
  })

  it('renders an error toast with role="alert" and the error styling, not the success one', async () => {
    const { wrapper, toast } = mountToast()

    toast.show('No se ha podido eliminar.', 'error')
    await wrapper.vm.$nextTick()

    const el = wrapper.find('.toast')
    expect(el.attributes('role')).toBe('alert')
    expect(el.classes()).toContain('alert-error')
    expect(el.classes()).not.toContain('alert-success')
  })
})
