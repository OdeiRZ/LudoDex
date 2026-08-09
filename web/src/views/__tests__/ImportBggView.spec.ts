import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ImportBggView from '@/views/ImportBggView.vue'
import { useGamesStore } from '@/stores/games'
import { i18n } from '@/i18n'

function mountImport() {
  setActivePinia(createPinia())
  const store = useGamesStore()

  const wrapper = mount(ImportBggView, {
    global: { stubs: { RouterLink: true }, plugins: [i18n] },
  })

  return { wrapper, store }
}

async function submitUsername(wrapper: ReturnType<typeof mountImport>['wrapper'], username = 'odei') {
  await wrapper.find('#bgg_username').setValue(username)
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

describe('ImportBggView', () => {
  beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows the pending state and keeps polling until the import completes', async () => {
    const { wrapper, store } = mountImport()
    vi.spyOn(store, 'startBggImport').mockResolvedValue({
      id: 'import-1',
      bgg_username: 'odei',
      status: 'pending',
      imported_count: null,
      error_message: null,
    })
    const pollSpy = vi.spyOn(store, 'pollBggImport').mockResolvedValue({
      id: 'import-1',
      bgg_username: 'odei',
      status: 'completed',
      imported_count: 12,
      error_message: null,
    })
    const fetchAllSpy = vi.spyOn(store, 'fetchAll').mockResolvedValue()

    await submitUsername(wrapper)

    expect(wrapper.find('[role="status"]').exists()).toBe(true)
    expect(pollSpy).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()

    expect(pollSpy).toHaveBeenCalledWith('import-1')
    expect(wrapper.text()).toContain('Importación completada: 12 juegos añadidos o actualizados.')
    expect(fetchAllSpy).toHaveBeenCalled()
  })

  it('shows the failure state with the backend message when the import fails while pending', async () => {
    const { wrapper, store } = mountImport()
    vi.spyOn(store, 'startBggImport').mockResolvedValue({
      id: 'import-1',
      bgg_username: 'odei',
      status: 'pending',
      imported_count: null,
      error_message: null,
    })
    vi.spyOn(store, 'pollBggImport').mockResolvedValue({
      id: 'import-1',
      bgg_username: 'odei',
      status: 'failed',
      imported_count: null,
      error_message: 'Ese usuario de BGG no existe.',
    })

    await submitUsername(wrapper)
    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()

    expect(wrapper.text()).toContain('Ese usuario de BGG no existe.')
    expect(wrapper.find('#bgg_username').exists()).toBe(true)
  })

  it('shows a generic error when starting the import throws', async () => {
    const { wrapper, store } = mountImport()
    vi.spyOn(store, 'startBggImport').mockRejectedValue(new Error('network error'))

    await submitUsername(wrapper)

    expect(wrapper.text()).toContain('No se ha podido iniciar la importación.')
  })

  it('stops polling once the component is unmounted', async () => {
    const { wrapper, store } = mountImport()
    vi.spyOn(store, 'startBggImport').mockResolvedValue({
      id: 'import-1',
      bgg_username: 'odei',
      status: 'pending',
      imported_count: null,
      error_message: null,
    })
    const pollSpy = vi.spyOn(store, 'pollBggImport').mockResolvedValue({
      id: 'import-1',
      bgg_username: 'odei',
      status: 'pending',
      imported_count: null,
      error_message: null,
    })

    await submitUsername(wrapper)
    wrapper.unmount()

    await vi.advanceTimersByTimeAsync(5000)
    await flushPromises()

    expect(pollSpy).not.toHaveBeenCalled()
  })
})
