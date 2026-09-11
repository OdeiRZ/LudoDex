import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import FriendsView from '@/views/FriendsView.vue'
import { useFriendsStore, type Friend } from '@/stores/friends'
import { useToastStore } from '@/stores/toast'
import { i18n } from '@/i18n'

function makeFriend(overrides: Partial<Friend> = {}): Friend {
  return {
    id: 1,
    name: 'Friend One',
    bgg_username: 'friend_one',
    avatar_url: null,
    ...overrides,
  }
}

// Only "friend-detail" (linked from each friend row's own "Colección")
// needs to exist here - FriendsView itself doesn't route anywhere else.
function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/friends/:friendId', name: 'friend-detail', component: { template: '<div />' } },
    ],
  })
}

function mountFriends(fetchAllImpl?: () => Promise<void>) {
  setActivePinia(createPinia())
  const store = useFriendsStore()
  vi.spyOn(store, 'fetchAll').mockImplementation(
    fetchAllImpl ??
      (async () => {
        store.loaded = true
      }),
  )

  const wrapper = mount(FriendsView, { global: { plugins: [makeRouter(), i18n] } })

  return { wrapper, store }
}

describe('FriendsView', () => {
  it('shows a loading state until the store finishes loading', async () => {
    const { wrapper } = mountFriends(() => new Promise(() => {}))
    await flushPromises()

    expect(wrapper.find('.loading-state').exists()).toBe(true)
  })

  it('gives the search-type select a real translated accessible name, not the literal i18n key', async () => {
    const { wrapper } = mountFriends()
    await flushPromises()

    expect(wrapper.find('select').attributes('aria-label')).toBe('Tipo de búsqueda')
  })

  it('shows the empty state when there are no friends yet', async () => {
    const { wrapper } = mountFriends()
    await flushPromises()

    expect(wrapper.text()).toContain('Todavía no tienes amigos añadidos.')
  })

  it('lists friends, incoming and outgoing requests once loaded', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    store.friends = [{ id: 1, user: makeFriend({ id: 1, name: 'Amigo Uno' }) }]
    store.incomingRequests = [{ id: 2, user: makeFriend({ id: 2, name: 'Pide Amistad' }) }]
    store.outgoingRequests = [{ id: 3, user: makeFriend({ id: 3, name: 'Solicitud Enviada' }) }]
    await flushPromises()

    expect(wrapper.text()).toContain('Amigo Uno')
    expect(wrapper.text()).toContain('Pide Amistad')
    expect(wrapper.text()).toContain('Solicitud Enviada')
  })

  it('searches by email and shows the result with a send-request button', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    const target = makeFriend({ id: 9, name: 'Encontrado' })
    vi.spyOn(store, 'searchByEmail').mockResolvedValue(target)

    await wrapper.find('input[type="text"]').setValue('encontrado@example.com')
    await wrapper.find('.search-form').trigger('submit')
    await flushPromises()

    expect(store.searchByEmail).toHaveBeenCalledWith('encontrado@example.com')
    expect(wrapper.text()).toContain('Encontrado')
  })

  it('searches by BGG username when that option is selected', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    vi.spyOn(store, 'searchByBggUsername').mockResolvedValue(null)

    await wrapper.find('select').setValue('bgg_username')
    await wrapper.find('input[type="text"]').setValue('un_usuario')
    await wrapper.find('.search-form').trigger('submit')
    await flushPromises()

    expect(store.searchByBggUsername).toHaveBeenCalledWith('un_usuario')
  })

  it('shows a not-found message when the search comes back empty', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    vi.spyOn(store, 'searchByEmail').mockResolvedValue(null)

    await wrapper.find('input[type="text"]').setValue('nadie@example.com')
    await wrapper.find('.search-form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('No se ha encontrado ningún usuario buscable con esos datos.')
  })

  it('shows the backend validation message, not the generic error, when the search itself fails with a 422', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    vi.spyOn(store, 'searchByEmail').mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: { errors: { email: ['El campo email debe ser una dirección de correo válida.'] } },
      },
    })

    await wrapper.find('input[type="text"]').setValue('no-es-un-email')
    await wrapper.find('.search-form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('El campo email debe ser una dirección de correo válida.')
    expect(wrapper.text()).not.toContain('Algo ha ido mal')
    // The search never actually ran - showing "not found" alongside the
    // validation error would wrongly imply it did.
    expect(wrapper.find('.search-result').exists()).toBe(false)
  })

  it('sends a friend request and clears the search result on success', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    const target = makeFriend({ id: 9, name: 'Encontrado' })
    vi.spyOn(store, 'searchByEmail').mockResolvedValue(target)
    vi.spyOn(store, 'sendRequest').mockResolvedValue()

    await wrapper.find('input[type="text"]').setValue('encontrado@example.com')
    await wrapper.find('.search-form').trigger('submit')
    await flushPromises()
    await wrapper.find('.search-result button').trigger('click')
    await flushPromises()

    expect(store.sendRequest).toHaveBeenCalledWith(target)
    expect(wrapper.find('.search-result').exists()).toBe(false)
  })

  it('shows the backend validation message when sending a request fails with a 422', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    const target = makeFriend({ id: 9, name: 'Encontrado' })
    vi.spyOn(store, 'searchByEmail').mockResolvedValue(target)
    vi.spyOn(store, 'sendRequest').mockRejectedValue({
      isAxiosError: true,
      response: { status: 422, data: { errors: { user_id: ['Ya sois amigos.'] } } },
    })

    await wrapper.find('input[type="text"]').setValue('encontrado@example.com')
    await wrapper.find('.search-form').trigger('submit')
    await flushPromises()
    await wrapper.find('.search-result button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Ya sois amigos.')
  })

  it('accepts an incoming request', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    store.incomingRequests = [{ id: 2, user: makeFriend({ id: 2, name: 'Pide Amistad' }) }]
    await flushPromises()
    vi.spyOn(store, 'acceptRequest').mockResolvedValue()

    await wrapper.find('.friend-row button.btn-primary').trigger('click')
    await flushPromises()

    expect(store.acceptRequest).toHaveBeenCalledWith(2)
  })

  it('declines an incoming request via removeRelationship', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    store.incomingRequests = [{ id: 2, user: makeFriend({ id: 2, name: 'Pide Amistad' }) }]
    await flushPromises()
    vi.spyOn(store, 'removeRelationship').mockResolvedValue()

    const buttons = wrapper.findAll('.friend-row button')
    await buttons[1]!.trigger('click')
    await flushPromises()

    expect(store.removeRelationship).toHaveBeenCalledWith(2)
  })

  it('cancels an outgoing request via removeRelationship', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    store.outgoingRequests = [{ id: 3, user: makeFriend({ id: 3, name: 'Solicitud Enviada' }) }]
    await flushPromises()
    vi.spyOn(store, 'removeRelationship').mockResolvedValue()

    await wrapper.find('.friend-row button').trigger('click')
    await flushPromises()

    expect(store.removeRelationship).toHaveBeenCalledWith(3)
  })

  it('requires a second click within the confirmation window before removing a friend', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    store.friends = [{ id: 1, user: makeFriend({ id: 1, name: 'Amigo Uno' }) }]
    await flushPromises()
    vi.spyOn(store, 'removeRelationship').mockResolvedValue()

    const button = wrapper.find('.friend-row button.btn-danger')
    await button.trigger('click')

    expect(store.removeRelationship).not.toHaveBeenCalled()
    expect(button.attributes('aria-label')).toBe('¿Seguro?')

    await button.trigger('click')
    await flushPromises()

    expect(store.removeRelationship).toHaveBeenCalledWith(1)
  })

  it('shows an error toast (without crashing) when removing a friend fails', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    store.friends = [{ id: 1, user: makeFriend({ id: 1, name: 'Amigo Uno' }) }]
    await flushPromises()
    vi.spyOn(store, 'removeRelationship').mockRejectedValue(new Error('network error'))

    const button = wrapper.find('.friend-row button.btn-danger')
    await button.trigger('click')
    await button.trigger('click')
    await flushPromises()

    // ToastNotification only lives in App.vue, not this view - checking
    // the store directly is what actually proves the catch fired instead
    // of leaving an unhandled rejection.
    expect(useToastStore().message).toBe('Algo ha ido mal. Inténtalo de nuevo.')
  })

  it('links each friend to their detail page', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    store.friends = [{ id: 1, user: makeFriend({ id: 42, name: 'Amigo Uno' }) }]
    await flushPromises()

    const link = wrapper.find('.friend-row a[href="/friends/42"]')
    expect(link.exists()).toBe(true)
    expect(link.attributes('aria-label')).toBe('Colección')
  })

  it('blocks a friend from the friends list, requiring a second click within the confirmation window', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    const target = makeFriend({ id: 42, name: 'Amigo Uno' })
    store.friends = [{ id: 1, user: target }]
    await flushPromises()
    vi.spyOn(store, 'blockUser').mockResolvedValue()

    const buttons = wrapper.findAll('.friend-row button')
    const button = buttons[buttons.length - 1]!
    await button.trigger('click')

    expect(store.blockUser).not.toHaveBeenCalled()
    expect(button.attributes('aria-label')).toBe('¿Seguro?')

    await button.trigger('click')
    await flushPromises()

    expect(store.blockUser).toHaveBeenCalledWith(target)
  })

  it('blocks a user from an incoming request, requiring a second click within the confirmation window', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    const target = makeFriend({ id: 2, name: 'Pide Amistad' })
    store.incomingRequests = [{ id: 2, user: target }]
    await flushPromises()
    vi.spyOn(store, 'blockUser').mockResolvedValue()

    const buttons = wrapper.findAll('.friend-row button')
    const button = buttons[buttons.length - 1]!
    await button.trigger('click')

    expect(store.blockUser).not.toHaveBeenCalled()
    expect(button.attributes('aria-label')).toBe('¿Seguro?')

    await button.trigger('click')
    await flushPromises()

    expect(store.blockUser).toHaveBeenCalledWith(target)
  })

  it('shows the blocked-users section only when there is at least one', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()

    expect(wrapper.text()).not.toContain('Usuarios bloqueados')

    store.blockedUsers = [{ id: 50, user: makeFriend({ id: 9, name: 'Bloqueado' }) }]
    await flushPromises()

    expect(wrapper.text()).toContain('Usuarios bloqueados')
    expect(wrapper.text()).toContain('Bloqueado')
  })

  it('unblocks a user', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    store.blockedUsers = [{ id: 50, user: makeFriend({ id: 9, name: 'Bloqueado' }) }]
    await flushPromises()
    vi.spyOn(store, 'unblockUser').mockResolvedValue()

    const section = wrapper.findAll('.card').find((card) => card.text().includes('Bloqueado'))!
    await section.find('button').trigger('click')
    await flushPromises()

    expect(store.unblockUser).toHaveBeenCalledWith(50)
  })

  it('shows a retry option instead of an endless spinner when the initial fetch fails', async () => {
    // Can't close over the outer `store` here - onMounted() (inside
    // mountFriends()) invokes this callback synchronously as part of
    // mounting, before mountFriends() has returned and assigned `store`
    // below, so useFriendsStore() is used instead to reach the same
    // (already-active-Pinia) instance.
    const { wrapper, store } = mountFriends(async () => {
      useFriendsStore().loadError = true
    })
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.find('.loading-state').exists()).toBe(false)

    vi.spyOn(store, 'fetchAll').mockResolvedValue()
    await wrapper.find('.load-error button').trigger('click')

    expect(store.fetchAll).toHaveBeenCalled()
  })
})
