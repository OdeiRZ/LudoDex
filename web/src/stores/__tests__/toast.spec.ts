import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useToastStore } from '@/stores/toast'

describe('toast store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows a message and clears it automatically after a few seconds', () => {
    const toast = useToastStore()

    toast.show('Juego añadido.')
    expect(toast.message).toBe('Juego añadido.')

    vi.advanceTimersByTime(3000)
    expect(toast.message).toBeNull()
  })

  it('replaces the current message and restarts the timer instead of queuing', () => {
    const toast = useToastStore()

    toast.show('Juego añadido.')
    vi.advanceTimersByTime(2000)

    toast.show('Cambios guardados.')
    expect(toast.message).toBe('Cambios guardados.')

    // The first call's timer would have fired here if it hadn't been
    // cleared - message must still be the second one, not null.
    vi.advanceTimersByTime(1000)
    expect(toast.message).toBe('Cambios guardados.')

    vi.advanceTimersByTime(2000)
    expect(toast.message).toBeNull()
  })

  it('defaults to the success type when none is given', () => {
    const toast = useToastStore()

    toast.show('Juego añadido.')

    expect(toast.type).toBe('success')
  })

  it('accepts an explicit error type', () => {
    const toast = useToastStore()

    toast.show('No se ha podido eliminar.', 'error')

    expect(toast.type).toBe('error')
  })

  it('resets to success on the next show() call if not given a type', () => {
    const toast = useToastStore()

    toast.show('No se ha podido eliminar.', 'error')
    toast.show('Cambios guardados.')

    expect(toast.type).toBe('success')
  })
})
