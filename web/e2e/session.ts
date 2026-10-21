import { expect, type APIRequestContext, type Page } from '@playwright/test'
import { API_URL } from './constants'

// Cada test crea su propio usuario por la API (más rápido y estable que pasar
// por los formularios) e inyecta la sesión en el navegador, como si ya
// estuviera iniciada. Lo que se prueba por la interfaz es lo que importa.

export interface Session {
  token: string
  authHeaders: { Authorization: string; Accept: string }
}

let counter = 0

export async function createSession(request: APIRequestContext): Promise<Session> {
  counter += 1
  const registered = await request.post(`${API_URL}/register`, {
    data: {
      name: 'Jugador E2E',
      email: `e2e-${Date.now()}-${counter}@example.test`,
      password: 'prueba-e2e-123',
      password_confirmation: 'prueba-e2e-123',
      device_name: 'e2e',
    },
    headers: { Accept: 'application/json' },
  })
  expect(registered.ok(), await registered.text()).toBeTruthy()
  const { token } = await registered.json()

  return { token, authHeaders: { Authorization: `Bearer ${token}`, Accept: 'application/json' } }
}

export async function addGame(
  request: APIRequestContext,
  session: Session,
  game: Record<string, unknown>,
) {
  const created = await request.post(`${API_URL}/games`, {
    data: game,
    headers: session.authHeaders,
  })
  expect(created.ok(), await created.text()).toBeTruthy()
  return (await created.json()).data
}

export async function createShareLink(
  request: APIRequestContext,
  session: Session,
): Promise<string> {
  const created = await request.post(`${API_URL}/share-link`, { headers: session.authHeaders })
  expect(created.ok(), await created.text()).toBeTruthy()
  return (await created.json()).data.token as string
}

export async function signIn(page: Page, session: Session) {
  await page.addInitScript((token) => {
    localStorage.setItem('ludodex_token', token)
  }, session.token)
}
