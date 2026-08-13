import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ImportBggView from '@/views/ImportBggView.vue'
import { useGamesStore, type BggCsvImportResult } from '@/stores/games'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
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
    // The pending-import id persisted to localStorage is what the next
    // couple of describe blocks are actually testing, but it would just
    // as easily leak in from any *other* test here that submits and never
    // reaches a terminal status (e.g. the unmount test below) - every test
    // in this file starts from a clean slate instead.
    localStorage.clear()
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
    expect(localStorage.getItem('ludodex_pending_bgg_import')).toBe('import-1')

    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()

    expect(pollSpy).toHaveBeenCalledWith('import-1')
    expect(wrapper.text()).toContain('Importación completada: 12 juegos añadidos o actualizados.')
    expect(fetchAllSpy).toHaveBeenCalled()
    expect(localStorage.getItem('ludodex_pending_bgg_import')).toBeNull()
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

  // A backgrounded tab getting suspended (or just reloaded to free memory)
  // loses every local ref - the persisted id in localStorage is the only
  // thing that survives, which is exactly what these cover.
  describe('resuming a pending import after a reload', () => {
    // mountImport() mounts as part of setup, which fires onMounted (and
    // so a real, unmocked pollBggImport call) before a spy attached
    // afterwards could intercept it - these need the store spied on
    // first, so setup is inlined instead of going through that helper.
    function mountWithPendingImport() {
      setActivePinia(createPinia())
      const store = useGamesStore()
      return { store, mount: () => mount(ImportBggView, { global: { stubs: { RouterLink: true }, plugins: [i18n] } }) }
    }

    it('picks the import back up on mount instead of showing a blank form', async () => {
      localStorage.setItem('ludodex_pending_bgg_import', 'import-1')
      const { store, mount: doMount } = mountWithPendingImport()
      const pollSpy = vi.spyOn(store, 'pollBggImport').mockResolvedValue({
        id: 'import-1',
        bgg_username: 'odei',
        status: 'pending',
        imported_count: null,
        error_message: null,
      })

      const wrapper = doMount()
      await flushPromises()

      expect(pollSpy).toHaveBeenCalledWith('import-1')
      expect(wrapper.find('[role="status"]').exists()).toBe(true)
      expect(wrapper.find('#bgg_username').exists()).toBe(false)

      // Stays "pending" for the rest of this test (pollBggImport is mocked
      // to always resolve pending) - same beforeunload-listener leak the
      // "warns and shows a banner" test below already guards against.
      wrapper.unmount()
    })

    it('clears the persisted id once a resumed import reaches a terminal state', async () => {
      localStorage.setItem('ludodex_pending_bgg_import', 'import-1')
      const { store, mount: doMount } = mountWithPendingImport()
      vi.spyOn(store, 'pollBggImport').mockResolvedValue({
        id: 'import-1',
        bgg_username: 'odei',
        status: 'completed',
        imported_count: 493,
        error_message: null,
      })
      vi.spyOn(store, 'fetchAll').mockResolvedValue()

      const wrapper = doMount()
      await flushPromises()

      expect(wrapper.text()).toContain('493')
      expect(localStorage.getItem('ludodex_pending_bgg_import')).toBeNull()
    })

    it('does nothing on mount when there is no persisted import', () => {
      const { wrapper } = mountImport()

      expect(wrapper.find('#bgg_username').exists()).toBe(true)
    })
  })

  describe('a network hiccup while polling', () => {
    it('keeps retrying rather than failing outright', async () => {
      const { wrapper, store } = mountImport()
      vi.spyOn(store, 'startBggImport').mockResolvedValue({
        id: 'import-1',
        bgg_username: 'odei',
        status: 'pending',
        imported_count: null,
        error_message: null,
      })
      const pollSpy = vi
        .spyOn(store, 'pollBggImport')
        .mockRejectedValueOnce(new Error('network error'))
        .mockResolvedValueOnce({
          id: 'import-1',
          bgg_username: 'odei',
          status: 'completed',
          imported_count: 5,
          error_message: null,
        })

      await submitUsername(wrapper)
      await vi.advanceTimersByTimeAsync(3000)
      await flushPromises()

      // Still pending/polling, not the failure screen - a dropped
      // connection alone shouldn't lose track of the import.
      expect(wrapper.find('[role="status"]').exists()).toBe(true)
      expect(wrapper.find('#bgg_username').exists()).toBe(false)

      await vi.advanceTimersByTimeAsync(3000)
      await flushPromises()

      expect(pollSpy).toHaveBeenCalledTimes(2)
      expect(wrapper.text()).toContain('Importación completada: 5 juegos añadidos o actualizados.')
    })

    it('stops and clears the persisted id when the server rejects the id outright', async () => {
      const { wrapper, store } = mountImport()
      vi.spyOn(store, 'startBggImport').mockResolvedValue({
        id: 'import-1',
        bgg_username: 'odei',
        status: 'pending',
        imported_count: null,
        error_message: null,
      })
      vi.spyOn(store, 'pollBggImport').mockRejectedValue({
        isAxiosError: true,
        response: { status: 404, data: {} },
      })

      await submitUsername(wrapper)
      await vi.advanceTimersByTimeAsync(3000)
      await flushPromises()

      expect(wrapper.find('#bgg_username').exists()).toBe(true)
      expect(localStorage.getItem('ludodex_pending_bgg_import')).toBeNull()
    })
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

  it('shows a plain count, with no "expansions skipped" clause, when none were skipped', async () => {
    const { wrapper, store } = mountImport()
    vi.spyOn(store, 'importBggCsv').mockResolvedValue({
      imported_count: 42,
      skipped_expansions_count: 0,
      skipped_no_status_count: 0,
      warnings: [],
    })
    vi.spyOn(store, 'fetchAll').mockResolvedValue()

    await submitCsv(wrapper)

    expect(wrapper.text()).toContain('Importados 42 juegos.')
    expect(wrapper.text()).not.toContain('omitidas')
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

  // Neither import actually gets interrupted by in-app navigation (the
  // request keeps running regardless of which view is rendered) - the real
  // risk is closing the tab or reloading mid-request, which these guard
  // against with the browser's native confirmation prompt.
  describe('warning before closing the tab', () => {
    function dispatchBeforeUnload(): Event {
      const event = new Event('beforeunload', { cancelable: true })
      window.dispatchEvent(event)
      return event
    }

    it('does not warn while idle', () => {
      mountImport()

      expect(dispatchBeforeUnload().defaultPrevented).toBe(false)
    })

    it('warns and shows a banner while a username import is pending', async () => {
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
        status: 'pending',
        imported_count: null,
        error_message: null,
      })

      await submitUsername(wrapper)

      expect(wrapper.text()).toContain('No cierres ni recargues esta pestaña mientras se importa.')
      expect(dispatchBeforeUnload().defaultPrevented).toBe(true)

      // Otherwise this listener (stuck "pending" forever, since polling is
      // mocked to always resolve pending) leaks into every later test in
      // this file - beforeunload listeners live on the shared `window`,
      // not the component, so an unmounted-but-never-cleaned-up instance
      // keeps answering for tests that mount a completely different one.
      wrapper.unmount()
    })

    it('warns and shows a banner while a CSV import is in flight', async () => {
      const { wrapper, store } = mountImport()
      let resolveImport: (value: BggCsvImportResult) => void = () => {}
      vi.spyOn(store, 'importBggCsv').mockImplementation(
        () => new Promise<BggCsvImportResult>((resolve) => { resolveImport = resolve }),
      )
      // A successful import triggers a real (unmocked) games.fetchAll()
      // call further down in this test - without stubbing it too, that
      // hits the network for real, which CI has no backend to answer and
      // fails as an unhandled rejection even though every assertion here
      // still passes.
      vi.spyOn(store, 'fetchAll').mockResolvedValue()

      await wrapper.find('[role="tab"]:nth-of-type(2)').trigger('click')
      const file = new File(['objectname,objectid'], 'collection.csv', { type: 'text/csv' })
      const input = wrapper.find('#csv_file')
      Object.defineProperty(input.element, 'files', { value: [file] })
      await input.trigger('change')
      wrapper.find('form').trigger('submit')
      await flushPromises()

      expect(wrapper.text()).toContain('No cierres ni recargues esta pestaña mientras se importa.')
      expect(wrapper.find('.csv-submitting').findComponent(LoadingSpinner).exists()).toBe(true)
      expect(dispatchBeforeUnload().defaultPrevented).toBe(true)

      resolveImport({ imported_count: 1, skipped_expansions_count: 0, skipped_no_status_count: 0, warnings: [] })
      await flushPromises()
      wrapper.unmount()
    })

    it('stops warning once unmounted', async () => {
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
        status: 'pending',
        imported_count: null,
        error_message: null,
      })

      await submitUsername(wrapper)
      wrapper.unmount()

      expect(dispatchBeforeUnload().defaultPrevented).toBe(false)
    })
  })
})
