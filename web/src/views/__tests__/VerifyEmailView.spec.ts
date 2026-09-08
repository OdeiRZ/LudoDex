import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import VerifyEmailView from '@/views/VerifyEmailView.vue'
import { i18n } from '@/i18n'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/verify-email', name: 'verify-email', component: VerifyEmailView }],
  })
}

// ok comes from the query string the API's redirect carries - the router
// has to already be on the target URL before mount(), same as clicking the
// real link from the verification email would land on it.
async function mountView(ok: string) {
  const router = makeRouter()
  await router.push({ name: 'verify-email', query: { ok } })
  const wrapper = mount(VerifyEmailView, { global: { plugins: [router, i18n] } })

  return { wrapper }
}

describe('VerifyEmailView', () => {
  it('shows a success message for ok=1, with no extra link - the header nav already covers navigation', async () => {
    const { wrapper } = await mountView('1')

    expect(wrapper.find('.alert-success').exists()).toBe(true)
    expect(wrapper.find('.alert-error').exists()).toBe(false)
    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('shows a failure message for ok=0, with no extra link', async () => {
    const { wrapper } = await mountView('0')

    expect(wrapper.find('.alert-error').exists()).toBe(true)
    expect(wrapper.find('.alert-success').exists()).toBe(false)
    expect(wrapper.find('a').exists()).toBe(false)
  })
})
