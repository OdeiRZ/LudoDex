import { afterEach, describe, expect, it } from 'vitest'
import { getLocale, setLocale } from '../index'

describe('setLocale', () => {
  const initial = getLocale()

  afterEach(() => {
    setLocale(initial)
  })

  it('keeps <html lang> in step with the interface language', () => {
    expect(document.documentElement.lang).toBe(getLocale())

    setLocale('en')
    expect(document.documentElement.lang).toBe('en')

    setLocale('es')
    expect(document.documentElement.lang).toBe('es')
  })
})
