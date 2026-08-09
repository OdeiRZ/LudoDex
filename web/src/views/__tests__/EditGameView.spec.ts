import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import EditGameView from '@/views/EditGameView.vue'
import { useGamesStore } from '@/stores/games'
import { makeEntry } from '@/stores/__tests__/gameFixtures'

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
    global: { plugins: [router] },
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
  })

  it('returns to the picker after saving when it came from there', async () => {
    const { wrapper, store, router } = await mountEdit('/games/g1/edit?from=picker', 'g1')
    vi.spyOn(store, 'updateGame').mockResolvedValue()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('picker')
  })
})
