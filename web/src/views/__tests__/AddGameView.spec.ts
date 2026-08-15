import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import AddGameView from '@/views/AddGameView.vue'
import { useGamesStore } from '@/stores/games'
import { useToastStore } from '@/stores/toast'
import { i18n } from '@/i18n'

function makeRouter() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'dashboard', component: { template: '<div>Dashboard</div>' } },
      { path: '/games/new', name: 'add-game', component: AddGameView },
    ],
  })
  router.push('/games/new')
  return router
}

// AddGameView registers a 'beforeunload' listener on the shared `window`
// for as long as it's mounted - without unmounting each wrapper, an
// instance left dirty by one test (e.g. after setting #name) keeps
// answering that check for every later test in this file.
let wrappers: VueWrapper[] = []

afterEach(() => {
  wrappers.forEach((wrapper) => wrapper.unmount())
  wrappers = []
})

async function mountAddGame() {
  setActivePinia(createPinia())
  const store = useGamesStore()
  store.loaded = true // skip the onMounted fetchAll() network call

  const router = makeRouter()
  await router.isReady()

  const wrapper = mount(AddGameView, { global: { plugins: [router, i18n] } })
  wrappers.push(wrapper)

  return { wrapper, store, router }
}

describe('AddGameView', () => {
  it('creates the game and redirects to the dashboard on success', async () => {
    const { wrapper, store, router } = await mountAddGame()
    vi.spyOn(store, 'createGame').mockResolvedValue()

    await wrapper.find('#name').setValue('Ark Nova')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(store.createGame).toHaveBeenCalledWith(expect.objectContaining({ name: 'Ark Nova' }))
    expect(router.currentRoute.value.name).toBe('dashboard')
    expect(useToastStore().message).toBe('Juego añadido.')
  })

  it('flattens field errors from a 422 response into a single list, instead of dropping them', async () => {
    const { wrapper, store, router } = await mountAddGame()
    vi.spyOn(store, 'createGame').mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: { errors: { name: ['El nombre es obligatorio.'], weight: ['El peso debe ser un número.'] } },
      },
    })

    await wrapper.find('#name').setValue('Ark Nova')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('El nombre es obligatorio.')
    expect(wrapper.text()).toContain('El peso debe ser un número.')
    expect(router.currentRoute.value.name).toBe('add-game')
  })

  it('shows a generic error for a non-validation failure', async () => {
    const { wrapper, store } = await mountAddGame()
    vi.spyOn(store, 'createGame').mockRejectedValue(new Error('network error'))

    await wrapper.find('#name').setValue('Ark Nova')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('No se ha podido guardar el juego. Revisa los datos.')
  })

  it('warns before closing/reloading the tab once the form has unsaved changes, not before', async () => {
    const { wrapper } = await mountAddGame()

    const pristineEvent = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(pristineEvent)
    expect(pristineEvent.defaultPrevented).toBe(false)

    await wrapper.find('#name').setValue('Ark Nova')

    const dirtyEvent = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(dirtyEvent)
    expect(dirtyEvent.defaultPrevented).toBe(true)
  })
})
