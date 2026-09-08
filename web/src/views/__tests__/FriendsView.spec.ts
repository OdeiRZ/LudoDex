import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import FriendsView from '@/views/FriendsView.vue'
import { useFriendsStore, type Friend } from '@/stores/friends'
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

function mountFriends(fetchAllImpl?: () => Promise<void>) {
  setActivePinia(createPinia())
  const store = useFriendsStore()
  vi.spyOn(store, 'fetchAll').mockImplementation(
    fetchAllImpl ??
      (async () => {
        store.loaded = true
      }),
  )

  const wrapper = mount(FriendsView, { global: { plugins: [i18n] } })

  return { wrapper, store }
}

describe('FriendsView', () => {
  it('shows a loading state until the store finishes loading', async () => {
    const { wrapper } = mountFriends(() => new Promise(() => {}))
    await flushPromises()

    expect(wrapper.find('.loading-state').exists()).toBe(true)
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

  it('removes a friend via removeRelationship', async () => {
    const { wrapper, store } = mountFriends()
    await flushPromises()
    store.friends = [{ id: 1, user: makeFriend({ id: 1, name: 'Amigo Uno' }) }]
    await flushPromises()
    vi.spyOn(store, 'removeRelationship').mockResolvedValue()

    await wrapper.find('.friend-row button.btn-danger').trigger('click')
    await flushPromises()

    expect(store.removeRelationship).toHaveBeenCalledWith(1)
  })
})
