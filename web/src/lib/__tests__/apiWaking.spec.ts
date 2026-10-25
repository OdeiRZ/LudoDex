import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  IDLE_BEFORE_SLEEP_MS,
  SLOW_AFTER_MS,
  apiWaking,
  requestStarted,
  resetApiWaking,
  responseArrived,
} from '../apiWaking'

describe('apiWaking', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    resetApiWaking()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('turns on when the first request of the session is slow, and off when it answers', () => {
    requestStarted()
    vi.advanceTimersByTime(SLOW_AFTER_MS - 1)
    expect(apiWaking.value).toBe(false)

    vi.advanceTimersByTime(1)
    expect(apiWaking.value).toBe(true)

    responseArrived()
    expect(apiWaking.value).toBe(false)
  })

  it('stays off for a slow request while the API has been answering (a BGG import)', () => {
    responseArrived(Date.now())
    requestStarted(Date.now() + 60_000)
    vi.advanceTimersByTime(SLOW_AFTER_MS * 10)
    expect(apiWaking.value).toBe(false)
  })

  it('turns on again after a long quiet spell, when the API may have gone to sleep', () => {
    const start = Date.now()
    responseArrived(start)
    requestStarted(start + IDLE_BEFORE_SLEEP_MS + 1)
    vi.advanceTimersByTime(SLOW_AFTER_MS)
    expect(apiWaking.value).toBe(true)
  })
})
