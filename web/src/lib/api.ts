import axios from 'axios'
import { getLocale } from '@/i18n'

// Token lives in localStorage (not an httpOnly cookie): the SPA and the API
// are on different domains with free-tier hosting, so cookie-based Sanctum
// auth would fight with browsers' third-party cookie restrictions. Bearer
// tokens sidestep that at the cost of being readable by any script on the
// page (XSS risk) - an accepted tradeoff for a portfolio project with no
// sensitive data, revisited if that ever changes.
const TOKEN_STORAGE_KEY = 'ludodex_token'

export function getStoredToken(): string | null {
  return localStorage.getItem(TOKEN_STORAGE_KEY)
}

export function storeToken(token: string): void {
  localStorage.setItem(TOKEN_STORAGE_KEY, token)
}

export function clearStoredToken(): void {
  localStorage.removeItem(TOKEN_STORAGE_KEY)
}

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api',
})

apiClient.interceptors.request.use((config) => {
  const token = getStoredToken()

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  // Lets the API return validation messages in whichever language the user
  // picked (see App\Http\Middleware\SetLocaleFromHeader), instead of always
  // Spanish regardless of the frontend's own language toggle.
  config.headers['Accept-Language'] = getLocale()

  return config
})
