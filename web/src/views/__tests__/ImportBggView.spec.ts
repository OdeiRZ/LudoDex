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

async function submitCsv(wrapper: ReturnType<typeof mountImport>['wrapper']) {
  await wrapper.find('[role="tab"]:nth-of-type(2)').trigger('click')

  const file = new File(['objectname,objectid'], 'collection.csv', { type: 'text/csv' })
  const input = wrapper.find('#csv_file')
  Object.defineProperty(input.element, 'files', { value: [file] })
  await input.trigger('change')

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

  it('imports a CSV file and shows the result summary', async () => {
    const { wrapper, store } = mountImport()
    const importSpy = vi.spyOn(store, 'importBggCsv').mockResolvedValue({
      imported_count: 107,
      skipped_expansions_count: 174,
      skipped_no_status_count: 0,
      warnings: [],
    })
    const fetchAllSpy = vi.spyOn(store, 'fetchAll').mockResolvedValue()

    await submitCsv(wrapper)

    expect(importSpy).toHaveBeenCalledWith(expect.objectContaining({ name: 'collection.csv' }))
    expect(wrapper.text()).toContain('Importados 107 juegos (174 expansiones omitidas por ahora).')
    expect(fetchAllSpy).toHaveBeenCalled()
  })

  it('shows warnings returned alongside a successful CSV import', async () => {
    const { wrapper, store } = mountImport()
    vi.spyOn(store, 'importBggCsv').mockResolvedValue({
      imported_count: 1,
      skipped_expansions_count: 0,
      skipped_no_status_count: 0,
      warnings: ['Aeon\'s End: no se ha reconocido el modo/jugadores en el comentario privado.'],
    })
    vi.spyOn(store, 'fetchAll').mockResolvedValue()

    await submitCsv(wrapper)

    expect(wrapper.text()).toContain('Avisos:')
    expect(wrapper.text()).toContain("Aeon's End: no se ha reconocido el modo/jugadores en el comentario privado.")
  })

  it('shows a generic error when the CSV import fails', async () => {
    const { wrapper, store } = mountImport()
    vi.spyOn(store, 'importBggCsv').mockRejectedValue(new Error('validation error'))

    await submitCsv(wrapper)

    expect(wrapper.text()).toContain('No se ha podido importar el archivo.')
    expect(wrapper.find('#csv_file').exists()).toBe(true)
  })
})
