import { test, expect } from '@playwright/test'
import { addGame, createSession, signIn } from './session'

// Prueba de humo de principio a fin: la app real (build de producción) contra el
// API real con una SQLite desechable. Los tests unitarios no ejercitan el
// conjunto: aquí se ve que la sesión entra, que lo guardado en el API sale por
// pantalla y que navegar por la app no deja vistas duplicadas ni rompe el CSP.

test('a game saved in the API shows in the collection and in the picker', async ({
  page,
  request,
}) => {
  const session = await createSession(request)
  await addGame(request, session, {
    name: 'Juego E2E',
    status: 'owned',
    min_players: 2,
    max_players: 4,
    min_playtime_minutes: 30,
    max_playtime_minutes: 60,
    is_competitive: true,
  })
  await signIn(page, session)

  await page.goto('./')
  await expect(page.getByText('Juego E2E').first()).toBeVisible()

  await page.goto('./picker')
  await expect(page.getByText('Juego E2E').first()).toBeVisible()
})

test('an expired or wrong token sends the visitor to the login, not to a broken screen', async ({
  page,
}) => {
  await page.addInitScript(() => localStorage.setItem('ludodex_token', 'token-invalido'))

  await page.goto('./')

  await expect(page).toHaveURL(/login/)
})

test('navigating between pages leaves a single <main> and breaks no CSP rule', async ({
  page,
  request,
}) => {
  const session = await createSession(request)
  await signIn(page, session)
  const violations: string[] = []
  page.on('console', (message) => {
    if (/content security policy/i.test(message.text())) violations.push(message.text())
  })

  for (const path of ['./', './picker', './plays', './friends', './profile']) {
    await page.goto(path)
    await expect(page.locator('main')).toHaveCount(1)
  }

  expect(violations).toEqual([])
})
