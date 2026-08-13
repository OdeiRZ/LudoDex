import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import EditGameView from '@/views/EditGameView.vue'
import { useGamesStore } from '@/stores/games'
import { useToastStore } from '@/stores/toast'
import { makeEntry } from '@/stores/__tests__/gameFixtures'
import { i18n } from '@/i18n'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'dashboard', component: { template: '<div>Dashboard</div>' } },
      { path: '/picker', name: 'picker', component: { template: '<div>Picker</div>' } },
      { path: '/games/:id/edit', name: 'edit-game', component: EditGameView, props: true },
    ],
  })
}

// EditGameView reads `route.query.from` via useRoute(), which works from a
// directly-mounted component as long as a real router is injected and
// already navigated to the target URL - no <RouterView> needed.
async function mountEdit(path: string, id: string) {
  setActivePinia(createPinia())
  const store = useGamesStore()
  store.collection = [makeEntry({ id, name: 'Root' })]
  store.loaded = true

  const router = makeRouter()
  router.push(path)
  await router.isReady()

  const wrapper = mount(EditGameView, {
    global: { plugins: [router, i18n] },
    props: { id },
  })
  await flushPromises()

  return { wrapper, store, router }
}

describe('EditGameView back navigation', () => {
  it('points "Volver" to the collection when there is no origin hint', async () => {
    const { wrapper } = await mountEdit('/games/g1/edit', 'g1')

    expect(wrapper.find('.back-link').attributes('href')).toBe('/')
  })

  it('points "Volver" to the picker when opened from there', async () => {
    const { wrapper } = await mountEdit('/games/g1/edit?from=picker', 'g1')

    expect(wrapper.find('.back-link').attributes('href')).toBe('/picker')
  })

  it('returns to the collection after saving by default', async () => {
    const { wrapper, store, router } = await mountEdit('/games/g1/edit', 'g1')
    vi.spyOn(store, 'updateGame').mockResolvedValue()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('dashboard')
    expect(useToastStore().message).toBe('Cambios guardados.')
  })

  it('returns to the picker after saving when it came from there', async () => {
    const { wrapper, store, router } = await mountEdit('/games/g1/edit?from=picker', 'g1')
    vi.spyOn(store, 'updateGame').mockResolvedValue()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('picker')
  })
})

describe('EditGameView delete', () => {
  it('does not delete on the first click, but arms a confirmation instead', async () => {
    const { wrapper, store } = await mountEdit('/games/g1/edit', 'g1')
    vi.spyOn(store, 'deleteGame').mockResolvedValue()

    await wrapper.find('.danger-zone button').trigger('click')

    expect(store.deleteGame).not.toHaveBeenCalled()
    expect(wrapper.find('.danger-zone button').text()).toContain('¿Seguro?')
  })

  it('deletes the game on a second click, returning to the collection with a confirmation toast', async () => {
    const { wrapper, store, router } = await mountEdit('/games/g1/edit', 'g1')
    vi.spyOn(store, 'deleteGame').mockResolvedValue()

    const button = wrapper.find('.danger-zone button')
    await button.trigger('click')
    await button.trigger('click')
    await flushPromises()

    expect(store.deleteGame).toHaveBeenCalledWith('g1')
    expect(router.currentRoute.value.name).toBe('dashboard')
    expect(useToastStore().message).toBe('Juego quitado de tu colección.')
  })

  it('reverts to the normal label if the second click never comes', async () => {
    // mountEdit resolves using real timers (its own flushPromises() calls
    // are themselves setTimeout-based) - only fake the clock afterwards, so
    // the setTimeout armed by the click below is the one actually mocked.
    const { wrapper } = await mountEdit('/games/g1/edit', 'g1')
    vi.useFakeTimers()

    await wrapper.find('.danger-zone button').trigger('click')
    expect(wrapper.find('.danger-zone button').text()).toContain('¿Seguro?')

    vi.advanceTimersByTime(4000)
    await wrapper.vm.$nextTick()
    vi.useRealTimers()

    expect(wrapper.find('.danger-zone button').text()).not.toContain('¿Seguro?')
    expect(wrapper.find('.danger-zone button').text()).toContain('Eliminar juego')
  })

  it('returns to the picker after deleting when it came from there', async () => {
    const { wrapper, store, router } = await mountEdit('/games/g1/edit?from=picker', 'g1')
    vi.spyOn(store, 'deleteGame').mockResolvedValue()

    const button = wrapper.find('.danger-zone button')
    await button.trigger('click')
    await button.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('picker')
  })

  it('shows a generic error, stays on the page, and un-arms the confirmation when deleting fails', async () => {
    const { wrapper, store, router } = await mountEdit('/games/g1/edit', 'g1')
    vi.spyOn(store, 'deleteGame').mockRejectedValue(new Error('network error'))

    const button = wrapper.find('.danger-zone button')
    await button.trigger('click')
    await button.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('No se ha podido eliminar el juego.')
    expect(router.currentRoute.value.name).toBe('edit-game')
    expect(wrapper.find('.danger-zone button').text()).not.toContain('¿Seguro?')
  })
})

describe('EditGameView errors', () => {
  it('flattens field errors from a 422 response into a single list, instead of dropping them', async () => {
    const { wrapper, store, router } = await mountEdit('/games/g1/edit', 'g1')
    vi.spyOn(store, 'updateGame').mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: { errors: { name: ['El nombre es obligatorio.'], weight: ['El peso debe ser un número.'] } },
      },
    })

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('El nombre es obligatorio.')
    expect(wrapper.text()).toContain('El peso debe ser un número.')
    expect(router.currentRoute.value.name).toBe('edit-game')
  })

  it('shows a generic error for a non-validation failure', async () => {
    const { wrapper, store } = await mountEdit('/games/g1/edit', 'g1')
    vi.spyOn(store, 'updateGame').mockRejectedValue(new Error('network error'))

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('No se ha podido guardar el juego. Revisa los datos.')
  })
})
