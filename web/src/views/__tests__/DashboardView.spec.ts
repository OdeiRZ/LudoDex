import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import DashboardView from '@/views/DashboardView.vue'
import { useAuthStore } from '@/stores/auth'
import { useGamesStore } from '@/stores/games'
import { makeEntry } from '@/stores/__tests__/gameFixtures'
import { i18n } from '@/i18n'

// Pre-seeding both stores as already loaded keeps onMounted from firing a
// real network request through fetchCurrentUser()/fetchAll().
function mountDashboard(entries: ReturnType<typeof makeEntry>[]) {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  auth.user = { id: 1, name: 'Odei', email: 'odei@example.com', bgg_username: null, avatar_url: null }

  const games = useGamesStore()
  games.collection = entries
  games.loaded = true

  const wrapper = mount(DashboardView, {
    global: { stubs: { RouterLink: true }, plugins: [i18n] },
  })

  return { wrapper, games }
}

describe('DashboardView', () => {
  // The density preference persists in localStorage across page loads -
  // clear it so one test's toggle doesn't leak into the next.
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows the empty state when the collection has no games', () => {
    const { wrapper } = mountDashboard([])

    expect(wrapper.text()).toContain('Todavía no has añadido ningún juego.')
  })

  it('lists every entry in the collection, owned or wishlisted', () => {
    const owned = makeEntry({ name: 'Root' }, 'owned')
    const wishlisted = makeEntry({ name: 'Ark Nova' }, 'wishlist')

    const { wrapper } = mountDashboard([owned, wishlisted])

    const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names).toEqual(['Root', 'Ark Nova'])
  })

  it('shows the collection count next to the title', () => {
    const { wrapper } = mountDashboard([makeEntry({ name: 'Root' }), makeEntry({ name: 'Ark Nova' })])

    expect(wrapper.find('.count').text()).toBe('2 juegos')
  })

  it('filters the collection by name and updates the count to match', async () => {
    const { wrapper } = mountDashboard([
      makeEntry({ name: 'Root' }),
      makeEntry({ name: 'Ark Nova' }),
      makeEntry({ name: 'Arkham Horror' }),
    ])

    await wrapper.find('input[type="search"]').setValue('ark')

    const names = wrapper.findAll('.game-card h2').map((h2) => h2.text())
    expect(names).toEqual(['Ark Nova', 'Arkham Horror'])
    expect(wrapper.find('.count').text()).toBe('2 juegos')
  })

  it('shows a no-matches message when the search matches nothing', async () => {
    const { wrapper } = mountDashboard([makeEntry({ name: 'Root' })])

    await wrapper.find('input[type="search"]').setValue('nonexistent')

    expect(wrapper.text()).toContain('Ningún juego de tu colección coincide con la búsqueda.')
    expect(wrapper.findAll('.game-card')).toHaveLength(0)
  })

  it('defaults to comfortable density and switches to compact on toggle, persisting the choice', async () => {
    const { wrapper } = mountDashboard([makeEntry({ name: 'Root' })])

    expect(wrapper.find('.games').classes()).not.toContain('compact')

    await wrapper.find('.density-toggle').trigger('click')

    expect(wrapper.find('.games').classes()).toContain('compact')
    expect(localStorage.getItem('ludodex-collection-density')).toBe('compact')
  })

  it('starts in compact density when that was the last stored choice', () => {
    localStorage.setItem('ludodex-collection-density', 'compact')

    const { wrapper } = mountDashboard([makeEntry({ name: 'Root' })])

    expect(wrapper.find('.games').classes()).toContain('compact')
  })

  it('removes a game from the collection when its remove button is clicked', async () => {
    const entry = makeEntry({ name: 'Root' })
    const { wrapper, games } = mountDashboard([entry])
    vi.spyOn(games, 'deleteGame').mockResolvedValue()

    await wrapper.find('.btn-danger').trigger('click')

    expect(games.deleteGame).toHaveBeenCalledWith(entry.id)
  })
})
