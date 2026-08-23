import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import GameDetailModal from '@/components/GameDetailModal.vue'
import { useGamesStore } from '@/stores/games'
import { makeGame, makeEntry } from '@/stores/__tests__/gameFixtures'
import { i18n, setLocale } from '@/i18n'

function mountModal(game: ReturnType<typeof makeGame>) {
  return mount(GameDetailModal, {
    props: { game },
    global: { plugins: [i18n] },
  })
}

describe('GameDetailModal', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  afterEach(() => {
    setLocale('es')
  })

  it('shows the empty state when the game has no description at all', () => {
    const wrapper = mountModal(makeGame({ description: null }))

    expect(wrapper.find('.modal-description-empty').exists()).toBe(true)
    expect(wrapper.find('.modal-description').exists()).toBe(false)
    expect(wrapper.find('.modal-translate').exists()).toBe(false)
  })

  it('shows the English text with an "EN" badge and a translate button when untranslated', () => {
    const wrapper = mountModal(makeGame({ description: 'A game about trade.', description_es: null }))

    expect(wrapper.find('.modal-description').text()).toContain('A game about trade.')
    expect(wrapper.find('.badge-en').exists()).toBe(true)
    expect(wrapper.find('.modal-translate').exists()).toBe(true)
  })

  it('hides the "EN" badge and translate button when the app itself is set to English', () => {
    setLocale('en')

    const wrapper = mountModal(makeGame({ description: 'A game about trade.', description_es: null }))

    expect(wrapper.find('.modal-description').text()).toContain('A game about trade.')
    expect(wrapper.find('.badge-en').exists()).toBe(false)
    expect(wrapper.find('.modal-translate').exists()).toBe(false)
  })

  it('shows the English original, not the Spanish translation, when the app is set to English', () => {
    setLocale('en')

    const wrapper = mountModal(
      makeGame({ description: 'A game about trade.', description_es: 'Un juego de comercio.' }),
    )

    expect(wrapper.find('.modal-description').text()).toBe('A game about trade.')
  })

  it('prefers the Spanish text and hides the badge/button once translated', () => {
    const wrapper = mountModal(
      makeGame({ description: 'A game about trade.', description_es: 'Un juego de comercio.' }),
    )

    expect(wrapper.find('.modal-description').text()).toBe('Un juego de comercio.')
    expect(wrapper.find('.badge-en').exists()).toBe(false)
    expect(wrapper.find('.modal-translate').exists()).toBe(false)
  })

  it('calls the store action and reflects the result once the translate button is clicked', async () => {
    // Sourced from the store's own reactive collection (rather than a
    // standalone makeGame() object) so a mutation on the same object
    // reference this test makes below is actually observable the same
    // way it would be for real - PickerView always passes an entry
    // straight out of games.collection, never a disconnected copy.
    const games = useGamesStore()
    games.collection = [makeEntry({ id: 'g1', description: 'A game about trade.', description_es: null })]
    const entry = games.collection[0]
    const wrapper = mountModal(entry.game)
    vi.spyOn(games, 'translateDescription').mockImplementation(async () => {
      entry.game.description_es = 'Un juego de comercio.'
      return 'Un juego de comercio.'
    })

    await wrapper.find('.modal-translate').trigger('click')
    await flushPromises()

    expect(games.translateDescription).toHaveBeenCalledWith('g1')
    expect(wrapper.find('.modal-description').text()).toBe('Un juego de comercio.')
    expect(wrapper.find('.modal-translate').exists()).toBe(false)
  })

  it('emits translated with the result, for callers whose own list isn\'t covered by the games.collection side effect', async () => {
    // A plain object, deliberately NOT part of games.collection - this is
    // what Partidas' own play.game looks like, since it's never added to
    // that store. Before this was fixed, the translation only ever
    // reached games.collection entries, so reopening this same object's
    // modal later (e.g. PlaysView keeping it in its own plays.entries
    // array) kept showing English despite the DB already having saved
    // the Spanish text - PlaysView listens for this event to update its
    // own entry instead (see its own test for that part).
    const standaloneGame = makeGame({ id: 'g1', description: 'A game about trade.', description_es: null })
    const wrapper = mountModal(standaloneGame)
    const games = useGamesStore()
    vi.spyOn(games, 'translateDescription').mockResolvedValue('Un juego de comercio.')

    await wrapper.find('.modal-translate').trigger('click')
    await flushPromises()

    expect(wrapper.find('.modal-description').text()).toBe('Un juego de comercio.')
    expect(wrapper.emitted('translated')).toEqual([['Un juego de comercio.']])
  })

  it('shows an error message instead of crashing when the translate request fails', async () => {
    const wrapper = mountModal(makeGame({ description: 'A game about trade.', description_es: null }))
    const games = useGamesStore()
    vi.spyOn(games, 'translateDescription').mockRejectedValue(new Error('network error'))

    await wrapper.find('.modal-translate').trigger('click')
    await flushPromises()

    expect(wrapper.find('.modal-translate-error').exists()).toBe(true)
    expect(wrapper.find('.modal-translate').exists()).toBe(true)
  })

  it('emits close when the close button is clicked', async () => {
    const wrapper = mountModal(makeGame())

    await wrapper.find('.modal-close').trigger('click')

    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('emits close when the Escape key is pressed', async () => {
    const wrapper = mountModal(makeGame())

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('emits close when the backdrop (not the panel itself) is clicked', async () => {
    const wrapper = mountModal(makeGame())

    await wrapper.find('.modal-backdrop').trigger('click')

    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('does not emit close when clicking inside the panel', async () => {
    const wrapper = mountModal(makeGame())

    await wrapper.find('.modal-panel').trigger('click')

    expect(wrapper.emitted('close')).toBeUndefined()
  })

  it('locks body scroll while open and restores it on close', () => {
    document.body.style.overflow = ''
    const wrapper = mountModal(makeGame())

    expect(document.body.style.overflow).toBe('hidden')

    wrapper.unmount()

    expect(document.body.style.overflow).toBe('')
  })

  it('restores whatever overflow the body already had, not just the default', () => {
    document.body.style.overflow = 'scroll'
    const wrapper = mountModal(makeGame())

    wrapper.unmount()

    expect(document.body.style.overflow).toBe('scroll')
    document.body.style.overflow = ''
  })
})
