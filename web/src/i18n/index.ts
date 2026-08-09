import { createI18n } from 'vue-i18n'
import es from './es'
import en from './en'

export type Locale = 'es' | 'en'

const STORAGE_KEY = 'ludodex-locale'

function initialLocale(): Locale {
  return localStorage.getItem(STORAGE_KEY) === 'en' ? 'en' : 'es'
}

export const i18n = createI18n({
  legacy: false,
  locale: initialLocale(),
  fallbackLocale: 'es',
  messages: { es, en },
})

export function getLocale(): Locale {
  return i18n.global.locale.value as Locale
}

export function setLocale(locale: Locale): void {
  i18n.global.locale.value = locale
  localStorage.setItem(STORAGE_KEY, locale)
}
