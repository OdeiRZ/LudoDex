import { createI18n } from 'vue-i18n'
import es from './es'
import en from './en'

export type Locale = 'es' | 'en'

const STORAGE_KEY = 'ludodex-locale'

// An explicit choice (made from Perfil, the only place to change it now
// that there's no header toggle) always wins. Without one yet - notably
// on login/register, where nobody's account exists to hold a preference -
// fall back to the browser's own language instead of hardcoding Spanish,
// so a recruiter browsing the portfolio in English sees these screens in
// English too. Same pattern already used in PequeDex.
function initialLocale(): Locale {
  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored === 'en' || stored === 'es') return stored

  return navigator.language.toLowerCase().startsWith('en') ? 'en' : 'es'
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
