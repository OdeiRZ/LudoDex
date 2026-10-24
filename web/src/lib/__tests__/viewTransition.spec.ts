import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { installViewTransitions } from '../viewTransition'

const Empty = { template: '<div />' }

function setup(reducedMotion = false) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: Empty },
      { path: '/picker', component: Empty },
    ],
  })
  const updates: Promise<void>[] = []
  const startViewTransition = vi.fn((update: () => Promise<void>) => {
    updates.push(update())
  })
  installViewTransitions(router, {
    startViewTransition,
    prefersReducedMotion: () => reducedMotion,
  })
  return { router, startViewTransition, updates }
}

describe('installViewTransitions', () => {
  it('wraps a screen change in a view transition that ends once the new view is in', async () => {
    const { router, startViewTransition, updates } = setup()
    await router.push('/')
    expect(startViewTransition).not.toHaveBeenCalled() // primera carga: sin transición

    await router.push('/picker')

    expect(startViewTransition).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.path).toBe('/picker')
    await expect(updates[0]).resolves.toBeUndefined()
  })

  it('skips the transition with reduced motion or when only the query changes', async () => {
    const reduced = setup(true)
    await reduced.router.push('/')
    await reduced.router.push('/picker')
    expect(reduced.startViewTransition).not.toHaveBeenCalled()

    const query = setup()
    await query.router.push('/')
    await query.router.push('/?q=azul')
    expect(query.startViewTransition).not.toHaveBeenCalled()
  })

  it('never leaves a transition waiting when a later guard cancels the navigation', async () => {
    const { router, updates } = setup()
    await router.push('/')
    router.beforeResolve((to) => (to.path === '/picker' ? false : undefined))

    await router.push('/picker')

    expect(router.currentRoute.value.path).toBe('/')
    expect(updates).toHaveLength(1)
    await expect(updates[0]).resolves.toBeUndefined()
  })

  it('does nothing without the browser API', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: Empty }],
    })
    installViewTransitions(router, { prefersReducedMotion: () => false })
    await router.push('/')
    expect(router.currentRoute.value.path).toBe('/')
  })
})
