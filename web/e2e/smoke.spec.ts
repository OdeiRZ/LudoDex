import { test, expect } from '@playwright/test'
import { addGame, createSession, createShareLink, signIn } from './session'
import { API_URL } from './constants'

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

test('a shared collection opens without a session: owned games and owner name, no wishlist, and it stops working when revoked', async ({
  page,
  request,
}) => {
  test.setTimeout(90_000)
  const session = await createSession(request)
  await addGame(request, session, { name: 'Juego Que Tengo', status: 'owned' })
  await addGame(request, session, { name: 'Juego Que Quiero', status: 'wishlist' })
  const token = await createShareLink(request, session)

  // Sin ninguna sesión en este navegador.
  await page.goto(`./c/${token}`)

  await expect(page.getByRole('heading', { name: /Colección de Jugador E2E/ })).toBeVisible({
    timeout: 45_000,
  })
  await expect(page.getByText('Juego Que Tengo')).toBeVisible()
  await expect(page.getByText('Juego Que Quiero')).toHaveCount(0)

  // El dueño deja de compartir: el mismo enlace ya no funciona.
  const revoked = await request.delete(`${API_URL}/share-link`, { headers: session.authHeaders })
  expect(revoked.ok()).toBeTruthy()
  await page.reload()
  await expect(page.getByTestId('public-not-found')).toBeVisible({ timeout: 45_000 })
})

test('the owner creates the link from the profile and it opens for someone without an account', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(120_000)
  const session = await createSession(request)
  await addGame(request, session, { name: 'Juego Compartido', status: 'owned' })
  await signIn(page, session)

  await page.goto('./profile')
  const card = page.getByTestId('share-card')
  await card.getByRole('button', { name: 'Crear enlace' }).click()
  const url = await card
    .getByRole('textbox', { name: 'Enlace público de tu colección' })
    .inputValue()
  expect(url).toMatch(/\/c\/[A-Za-z0-9_-]{43}$/)
  await expect(card.getByText('Caduca el')).toBeVisible()

  const stranger = await browser.newContext()
  const strangerPage = await stranger.newPage()
  await strangerPage.goto(url)
  await expect(strangerPage.getByText('Juego Compartido')).toBeVisible({ timeout: 45_000 })
  await stranger.close()
})

test('the privacy policy is public: it opens without a session and names the contact', async ({
  page,
}) => {
  await page.goto('./privacidad')

  await expect(page).toHaveURL(/privacidad/)
  await expect(page.getByRole('heading', { name: 'Política de privacidad' })).toBeVisible()
  await expect(page.getByText('soporteludodex@gmail.com').first()).toBeVisible()
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

  for (const path of ['./', './picker', './plays', './friends', './profile', './privacidad']) {
    await page.goto(path)
    await expect(page.locator('main')).toHaveCount(1)
  }

  expect(violations).toEqual([])
})
