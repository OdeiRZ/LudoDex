import { defineStore } from 'pinia'

const DISPLAY_MS = 3000

export type ToastType = 'success' | 'error'

interface ToastState {
  message: string | null
  type: ToastType
  timeoutId: ReturnType<typeof setTimeout> | null
}

export const useToastStore = defineStore('toast', {
  state: (): ToastState => ({
    message: null,
    type: 'success',
    timeoutId: null,
  }),

  actions: {
    // A single current message, not a queue: every caller so far is a
    // one-off confirmation right after a mutation (save, delete...), never
    // several at once, so a queue would be complexity nothing needs yet.
    // Showing a new one while another is visible just replaces it and
    // restarts the timer. Defaults to 'success' since most call sites
    // already were a success confirmation before `type` existed - found
    // via an audit that a couple of failure-path callers (Dashboard's
    // remove/clear errors) were passing an error message through here
    // too, rendered with the exact same green/role="status" styling as
    // an actual success, with nothing distinguishing the two.
    show(message: string, type: ToastType = 'success') {
      if (this.timeoutId) {
        clearTimeout(this.timeoutId)
      }

      this.message = message
      this.type = type
      this.timeoutId = setTimeout(() => {
        this.message = null
        this.timeoutId = null
      }, DISPLAY_MS)
    },
  },
})
