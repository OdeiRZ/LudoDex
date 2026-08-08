import { ref } from 'vue'

const SLOW_AFTER_MS = 4000

/**
 * Render's free tier spins the API down after inactivity; the first request
 * afterwards can take ~50s to respond while it wakes back up. Without any
 * feedback that looks identical to the app being frozen, so `wrap()` flips
 * `isSlow` on if the given promise hasn't settled after a few seconds.
 */
export function useSlowRequestHint() {
  const isSlow = ref(false)

  async function wrap<T>(promise: Promise<T>): Promise<T> {
    isSlow.value = false
    const timer = setTimeout(() => {
      isSlow.value = true
    }, SLOW_AFTER_MS)

    try {
      return await promise
    } finally {
      clearTimeout(timer)
      isSlow.value = false
    }
  }

  return { isSlow, wrap }
}
