import { describe, it, expect, vi, beforeEach } from 'vitest'
import { computed, ref } from 'vue'
import {
  useCollectionScrubber,
  normalizeLetter,
  yearBucket,
  rankBucket,
  type ScrubberCriterion,
} from '@/composables/useCollectionScrubber'
import { makeEntry } from '@/stores/__tests__/gameFixtures'
import type { UserGame } from '@/stores/games'

// jsdom implements neither of these (real Pointer Capture / scroll APIs) -
// stubbed here rather than in the shared test-setup, since no other spec
// touches pointer interaction. localStorage is real in jsdom and persists
// across tests unless cleared - the drag-handle position below reads it on
// every useCollectionScrubber() call, so a previous test's stored value
// would otherwise leak into the next one.
beforeEach(() => {
  Element.prototype.setPointerCapture = vi.fn()
  Element.prototype.scrollIntoView = vi.fn()
  localStorage.clear()
})

function fakePointerEvent(overrides: Partial<PointerEvent> & { clientY: number; currentTarget: EventTarget }) {
  return { pointerId: 1, pointerType: 'mouse', ...overrides } as PointerEvent
}

function setup(entries: UserGame[], overrides: { criterion?: ScrubberCriterion; order?: 'asc' | 'desc' } = {}) {
  const sortCriterion = ref<ScrubberCriterion>(overrides.criterion ?? 'name')
  const sortOrder = ref<'asc' | 'desc'>(overrides.order ?? 'asc')
  const pool = computed(() => entries)
  const filtered = computed(() => entries)
  const listEl = document.createElement('ul')
  const listRef = ref<HTMLElement | null>(listEl)
  const hidden = ref(false)
  const labels = computed(() => ({ name: 'Saltar a una letra', year: 'Saltar a una década', rank: 'Saltar a un tramo' }))

  const scrubber = useCollectionScrubber({
    sortCriterion,
    sortOrder,
    pool,
    filtered,
    listRef,
    hidden: computed(() => hidden.value),
    labels,
  })

  return { scrubber, sortCriterion, sortOrder, hidden, listEl }
}

// A baseline collection large enough (>12) to satisfy showScrubber's own
// threshold, spanning several letters/decades/rank tiers so the "which
// buckets exist" logic has real variety to work with.
function bigCollection(): UserGame[] {
  const entries: UserGame[] = []
  const names = [
    'Aventureros',
    'Brass',
    'Catan',
    'Dune',
    'Everdell',
    'Faraway',
    'Gloomhaven',
    'Hive',
    'Inis',
    'Jaipur',
    'Karuba',
    'Lisboa',
    'Merchants',
  ]
  names.forEach((name, i) => {
    entries.push(makeEntry({ name, year_published: 1990 + i, bgg_rank: (i + 1) * 100 }))
  })
  return entries
}

describe('normalizeLetter', () => {
  it('uppercases the first letter of a plain name', () => {
    expect(normalizeLetter('catan')).toBe('C')
  })

  it('strips accents before reading the first letter', () => {
    expect(normalizeLetter('Álamo')).toBe('A')
  })

  it('buckets a name starting with a non-letter under "#"', () => {
    expect(normalizeLetter('¡Aventureros al Tren!')).toBe('#')
  })
})

describe('yearBucket', () => {
  it('floors a year down to its own decade', () => {
    expect(yearBucket(1994)).toBe('1990')
    expect(yearBucket(2020)).toBe('2020')
  })

  it('returns the unknown marker for a null year', () => {
    expect(yearBucket(null)).toBe('?')
  })
})

describe('rankBucket', () => {
  it.each([
    [1, '≤100'],
    [100, '≤100'],
    [101, '101-500'],
    [500, '101-500'],
    [501, '501-1k'],
    [1000, '501-1k'],
    [1001, '1k-5k'],
    [5000, '1k-5k'],
    [5001, '5k+'],
  ])('buckets rank %i as %s', (rank, expected) => {
    expect(rankBucket(rank)).toBe(expected)
  })

  it('returns the unranked marker for a null rank', () => {
    expect(rankBucket(null)).toBe('?')
  })
})

describe('useCollectionScrubber - visibility', () => {
  it('shows the scrubber once there are more than 12 entries, sorted by name/year/rank', () => {
    const { scrubber } = setup(bigCollection())
    expect(scrubber.showScrubber.value).toBe(true)
  })

  it('hides the scrubber at 12 entries or fewer', () => {
    const { scrubber } = setup(bigCollection().slice(0, 12))
    expect(scrubber.showScrubber.value).toBe(false)
  })

  it('hides the scrubber while sorted by rank is not being used (e.g. unsupported criterion)', () => {
    // @ts-expect-error - deliberately testing an out-of-union value defensively
    const { scrubber } = setup(bigCollection(), { criterion: 'other' })
    expect(scrubber.showScrubber.value).toBe(false)
  })

  it('force-hides the scrubber when `hidden` is true regardless of everything else', () => {
    const { scrubber, hidden } = setup(bigCollection())
    expect(scrubber.showScrubber.value).toBe(true)
    hidden.value = true
    expect(scrubber.showScrubber.value).toBe(false)
  })
})

describe('useCollectionScrubber - name mode buckets', () => {
  it('always exposes the full A-Z alphabet (plus "#") regardless of what is in the collection', () => {
    const { scrubber } = setup(bigCollection())
    expect(scrubber.displayBuckets.value).toEqual(['#', ...'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('')])
  })

  it('marks only the letters actually present as available', () => {
    const { scrubber } = setup(bigCollection())
    expect(scrubber.availableBuckets.value.has('C')).toBe(true)
    expect(scrubber.availableBuckets.value.has('Z')).toBe(false)
  })

  it('reverses the whole alphabet, "#" included, when sorted Z-A', () => {
    const { scrubber } = setup(bigCollection(), { order: 'desc' })
    expect(scrubber.displayBuckets.value[0]).toBe('Z')
    expect(scrubber.displayBuckets.value[scrubber.displayBuckets.value.length - 1]).toBe('#')
  })
})

describe('useCollectionScrubber - year mode buckets', () => {
  it('only includes decades actually present in the pool, not the whole alphabet', () => {
    const { scrubber } = setup(bigCollection(), { criterion: 'year' })
    expect(scrubber.displayBuckets.value).toEqual(['1990', '2000'])
  })

  it('adds a trailing "?" bucket once at least one game has no known year', () => {
    const entries = bigCollection()
    entries.push(makeEntry({ name: 'Mystery', year_published: null, bgg_rank: null }))
    const { scrubber } = setup(entries, { criterion: 'year' })
    expect(scrubber.displayBuckets.value[scrubber.displayBuckets.value.length - 1]).toBe('?')
  })

  it('omits the "?" bucket entirely when every game has a known year', () => {
    const { scrubber } = setup(bigCollection(), { criterion: 'year' })
    expect(scrubber.displayBuckets.value).not.toContain('?')
  })

  it('keeps decade slots stable against `pool`, not `filtered`, so a search does not remove/add slots', () => {
    const sortCriterion = ref<ScrubberCriterion>('year')
    const sortOrder = ref<'asc' | 'desc'>('asc')
    const entries = bigCollection()
    const pool = computed(() => entries)
    // Search narrows filtered down to just one entry from the 1990s.
    const filtered = computed(() => [entries[0]])
    const listRef = ref<HTMLElement | null>(document.createElement('ul'))
    const labels = computed(() => ({ name: '', year: '', rank: '' }))

    const scrubber = useCollectionScrubber({
      sortCriterion,
      sortOrder,
      pool,
      filtered,
      listRef,
      hidden: computed(() => false),
      labels,
    })

    // Both decades still show as slots (from `pool`) even though `filtered`
    // only contains a 1990s game.
    expect(scrubber.displayBuckets.value).toEqual(['1990', '2000'])
    // But only the 1990s bucket reads as available - the 2000s slot is
    // still a valid target, just dimmed.
    expect(scrubber.availableBuckets.value.has('1990')).toBe(true)
    expect(scrubber.availableBuckets.value.has('2000')).toBe(false)
  })

  it('reverses the decades but keeps "?" pinned to the end in both directions', () => {
    const entries = bigCollection()
    entries.push(makeEntry({ name: 'Mystery', year_published: null, bgg_rank: null }))
    const { scrubber } = setup(entries, { criterion: 'year', order: 'desc' })
    expect(scrubber.displayBuckets.value).toEqual(['2000', '1990', '?'])
  })
})

describe('useCollectionScrubber - rank mode buckets', () => {
  it('uses the fixed rank tiers regardless of what the collection actually spans', () => {
    const { scrubber } = setup(bigCollection(), { criterion: 'rank' })
    expect(scrubber.displayBuckets.value.slice(0, 5)).toEqual(['≤100', '101-500', '501-1k', '1k-5k', '5k+'])
  })

  it('adds a trailing "?" only while at least one game is unranked', () => {
    const entries = bigCollection()
    entries.push(makeEntry({ name: 'Homebrew', year_published: 2020, bgg_rank: null }))
    const { scrubber } = setup(entries, { criterion: 'rank' })
    expect(scrubber.displayBuckets.value[scrubber.displayBuckets.value.length - 1]).toBe('?')

    const { scrubber: allRanked } = setup(bigCollection(), { criterion: 'rank' })
    expect(allRanked.displayBuckets.value).not.toContain('?')
  })

  it('reverses the tiers but keeps "?" pinned to the end', () => {
    const entries = bigCollection()
    entries.push(makeEntry({ name: 'Homebrew', year_published: 2020, bgg_rank: null }))
    const { scrubber } = setup(entries, { criterion: 'rank', order: 'desc' })
    expect(scrubber.displayBuckets.value).toEqual(['5k+', '1k-5k', '501-1k', '101-500', '≤100', '?'])
  })
})

describe('useCollectionScrubber - aria label', () => {
  it('picks the label matching the active sort criterion', () => {
    const nameCase = setup(bigCollection(), { criterion: 'name' })
    expect(nameCase.scrubber.scrubberAriaLabel.value).toBe('Saltar a una letra')

    const yearCase = setup(bigCollection(), { criterion: 'year' })
    expect(yearCase.scrubber.scrubberAriaLabel.value).toBe('Saltar a una década')

    const rankCase = setup(bigCollection(), { criterion: 'rank' })
    expect(rankCase.scrubber.scrubberAriaLabel.value).toBe('Saltar a un tramo')
  })
})

describe('useCollectionScrubber - hover state', () => {
  it('turns hovering on and off', () => {
    const { scrubber } = setup(bigCollection())
    expect(scrubber.hovering.value).toBe(false)
    scrubber.onScrubberEnter()
    expect(scrubber.hovering.value).toBe(true)
    scrubber.onScrubberLeave()
    expect(scrubber.hovering.value).toBe(false)
  })
})

describe('useCollectionScrubber - drag/jump behavior', () => {
  it('jumps to the first matching element for the bucket under the pointer', () => {
    const { scrubber, listEl } = setup(bigCollection())
    const target = document.createElement('li')
    target.setAttribute('data-letter', 'C')
    listEl.appendChild(target)

    const container = document.createElement('div')
    vi.spyOn(container, 'getBoundingClientRect').mockReturnValue({
      top: 0,
      height: 270, // 27 buckets * 10px each, so bucket index = clientY / 10
      left: 0,
      width: 30,
      right: 30,
      bottom: 270,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    })

    // "C" is index 3 in ['#','A','B','C',...] -> ratio 3/27, clientY = 30.
    scrubber.startScrub(fakePointerEvent({ clientY: 30, currentTarget: container }))

    expect(scrubber.scrubbing.value).toBe(true)
    expect(scrubber.hovering.value).toBe(true)
    expect(scrubber.scrubLetter.value).toBe('C')
    expect(target.scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'start' })
  })

  it('positions the bubble at the pointer, not fixed to the strip center', () => {
    const { scrubber } = setup(bigCollection())
    const container = document.createElement('div')
    vi.spyOn(container, 'getBoundingClientRect').mockReturnValue({
      top: 0,
      height: 270,
      left: 0,
      width: 30,
      right: 30,
      bottom: 270,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    })

    scrubber.startScrub(fakePointerEvent({ clientY: 30, currentTarget: container }))
    expect(scrubber.scrubBubbleTop.value).toBe(30)

    scrubber.moveScrub(fakePointerEvent({ clientY: 200, currentTarget: container }))
    expect(scrubber.scrubBubbleTop.value).toBe(200)
  })

  it('clamps the bubble to the strip bounds when the pointer drags past either edge', () => {
    const { scrubber } = setup(bigCollection())
    const container = document.createElement('div')
    vi.spyOn(container, 'getBoundingClientRect').mockReturnValue({
      top: 50,
      height: 270,
      left: 0,
      width: 30,
      right: 30,
      bottom: 320,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    })

    scrubber.startScrub(fakePointerEvent({ clientY: -100, currentTarget: container }))
    expect(scrubber.scrubBubbleTop.value).toBe(50)

    scrubber.moveScrub(fakePointerEvent({ clientY: 900, currentTarget: container }))
    expect(scrubber.scrubBubbleTop.value).toBe(320)
  })

  it('snaps to the nearest available bucket when the one under the pointer has no games', () => {
    // Only "C" and "Z" are available; the pointer lands on "M", which
    // isn't - "C" is 10 letters away, "Z" is 13, so it should resolve to
    // "C" rather than doing nothing.
    const entries = [makeEntry({ name: 'Catan' }), makeEntry({ name: 'Zombicide' })]
    const { scrubber, listEl } = setup(entries)
    const target = document.createElement('li')
    target.setAttribute('data-letter', 'C')
    listEl.appendChild(target)

    const container = document.createElement('div')
    vi.spyOn(container, 'getBoundingClientRect').mockReturnValue({
      top: 0,
      height: 270,
      left: 0,
      width: 30,
      right: 30,
      bottom: 270,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    })

    // Index of 'M' in the alphabet array is 13 -> clientY ~130.
    scrubber.startScrub(fakePointerEvent({ clientY: 130, currentTarget: container }))

    expect(scrubber.scrubLetter.value).toBe('C')
  })

  it('does nothing while dragging if no bucket is available at all', () => {
    const sortCriterion = ref<ScrubberCriterion>('name')
    const sortOrder = ref<'asc' | 'desc'>('asc')
    const listEl = document.createElement('ul')
    const scrubber = useCollectionScrubber({
      sortCriterion,
      sortOrder,
      pool: computed(() => []),
      filtered: computed(() => []),
      listRef: ref(listEl),
      hidden: computed(() => false),
      labels: computed(() => ({ name: '', year: '', rank: '' })),
    })

    const container = document.createElement('div')
    vi.spyOn(container, 'getBoundingClientRect').mockReturnValue({
      top: 0,
      height: 270,
      left: 0,
      width: 30,
      right: 30,
      bottom: 270,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    })

    scrubber.startScrub(fakePointerEvent({ clientY: 30, currentTarget: container }))

    expect(scrubber.scrubLetter.value).toBe(null)
  })

  it('ignores pointermove while not actively scrubbing', () => {
    const { scrubber } = setup(bigCollection())
    const container = document.createElement('div')
    vi.spyOn(container, 'getBoundingClientRect').mockReturnValue({
      top: 0,
      height: 270,
      left: 0,
      width: 30,
      right: 30,
      bottom: 270,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    })

    scrubber.moveScrub(fakePointerEvent({ clientY: 30, currentTarget: container }))

    expect(scrubber.scrubLetter.value).toBe(null)
  })

  it('queries the right data attribute for each sort criterion when jumping', () => {
    for (const [criterion, attr] of [
      ['name', 'data-letter'],
      ['year', 'data-year-bucket'],
      ['rank', 'data-rank-bucket'],
    ] as const) {
      const { scrubber, listEl } = setup(bigCollection(), { criterion })
      const querySpy = vi.spyOn(listEl, 'querySelector')
      const container = document.createElement('div')
      vi.spyOn(container, 'getBoundingClientRect').mockReturnValue({
        top: 0,
        height: 100,
        left: 0,
        width: 30,
        right: 30,
        bottom: 100,
        x: 0,
        y: 0,
        toJSON: () => ({}),
      })

      scrubber.startScrub(fakePointerEvent({ clientY: 0, currentTarget: container }))

      expect(querySpy).toHaveBeenCalledWith(expect.stringContaining(attr))
    }
  })

  it('clears scrubbing state on release, and only clears hovering for a non-mouse pointer', () => {
    const { scrubber } = setup(bigCollection())
    scrubber.scrubbing.value = true
    scrubber.hovering.value = true

    scrubber.endScrub({ pointerType: 'mouse' } as PointerEvent)
    expect(scrubber.scrubbing.value).toBe(false)
    expect(scrubber.scrubLetter.value).toBe(null)
    expect(scrubber.hovering.value).toBe(true)

    scrubber.hovering.value = true
    scrubber.endScrub({ pointerType: 'touch' } as PointerEvent)
    expect(scrubber.hovering.value).toBe(false)
  })
})

// Sorting by rank widens the strip enough to sit on top of each card's own
// "view details" button on a real phone (reported directly) - the drag
// handle lets it be moved out of the way once instead of chasing the
// overlap per sort mode/screen size.
describe('useCollectionScrubber - drag-handle repositioning', () => {
  function stubViewport(width: number, height: number) {
    vi.stubGlobal('innerWidth', width)
    vi.stubGlobal('innerHeight', height)
  }

  function stubStripRect(el: HTMLElement, rect: { top: number; left: number; width: number; height: number }) {
    vi.spyOn(el, 'getBoundingClientRect').mockReturnValue({
      top: rect.top,
      left: rect.left,
      width: rect.width,
      height: rect.height,
      right: rect.left + rect.width,
      bottom: rect.top + rect.height,
      x: rect.left,
      y: rect.top,
      toJSON: () => ({}),
    })
  }

  it('uses the default CSS position (no inline style) when nothing has been dragged yet', () => {
    const { scrubber } = setup(bigCollection())
    expect(scrubber.scrubberStyle.value).toEqual({})
  })

  it('moves the strip by exactly the pointer delta while dragging the handle', () => {
    stubViewport(1000, 800)
    const { scrubber } = setup(bigCollection())
    const strip = document.createElement('div')
    stubStripRect(strip, { top: 100, left: 200, width: 50, height: 300 })
    scrubber.scrubberRef.value = strip
    const handle = document.createElement('div')

    scrubber.startHandleDrag(fakePointerEvent({ clientY: 100, clientX: 200, currentTarget: handle }))
    scrubber.moveHandleDrag(fakePointerEvent({ clientY: 120, clientX: 210, currentTarget: handle }))

    expect(scrubber.scrubberStyle.value).toEqual({
      top: '120px',
      left: '210px',
      right: 'auto',
      transform: 'none',
    })
  })

  it('keeps the pointer-to-corner offset from drag start, not just the raw pointer position', () => {
    stubViewport(1000, 800)
    const { scrubber } = setup(bigCollection())
    const strip = document.createElement('div')
    // Grabbed 15px right / 10px down from the strip's own top-left corner.
    stubStripRect(strip, { top: 100, left: 200, width: 50, height: 300 })
    scrubber.scrubberRef.value = strip
    const handle = document.createElement('div')

    scrubber.startHandleDrag(fakePointerEvent({ clientY: 110, clientX: 215, currentTarget: handle }))
    scrubber.moveHandleDrag(fakePointerEvent({ clientY: 110, clientX: 215, currentTarget: handle }))

    // No net movement yet, but the corner should land back where the strip
    // already was, not snap to (215, 110) as if the offset were zero.
    expect(scrubber.scrubberStyle.value).toMatchObject({ top: '100px', left: '200px' })
  })

  it('clamps the drag so the whole strip stays on-screen', () => {
    stubViewport(1000, 800)
    const { scrubber } = setup(bigCollection())
    const strip = document.createElement('div')
    stubStripRect(strip, { top: 100, left: 200, width: 50, height: 300 })
    scrubber.scrubberRef.value = strip
    const handle = document.createElement('div')

    scrubber.startHandleDrag(fakePointerEvent({ clientY: 100, clientX: 200, currentTarget: handle }))
    scrubber.moveHandleDrag(fakePointerEvent({ clientY: -500, clientX: -500, currentTarget: handle }))
    expect(scrubber.scrubberStyle.value).toMatchObject({ top: '0px', left: '0px' })

    scrubber.moveHandleDrag(fakePointerEvent({ clientY: 5000, clientX: 5000, currentTarget: handle }))
    expect(scrubber.scrubberStyle.value).toMatchObject({ top: '500px', left: '950px' })
  })

  it('persists the dragged position to localStorage and reloads it on the next mount', () => {
    stubViewport(1000, 800)
    const { scrubber } = setup(bigCollection())
    const strip = document.createElement('div')
    stubStripRect(strip, { top: 100, left: 200, width: 50, height: 300 })
    scrubber.scrubberRef.value = strip
    const handle = document.createElement('div')

    scrubber.startHandleDrag(fakePointerEvent({ clientY: 100, clientX: 200, currentTarget: handle }))
    scrubber.moveHandleDrag(fakePointerEvent({ clientY: 150, clientX: 250, currentTarget: handle }))

    expect(JSON.parse(localStorage.getItem('ludodex-scrubber-position') ?? 'null')).toEqual({
      top: 150,
      left: 250,
    })

    const { scrubber: reloaded } = setup(bigCollection())
    expect(reloaded.scrubberStyle.value).toMatchObject({ top: '150px', left: '250px' })
  })

  it('resets to the default position on double-click, clearing localStorage too', () => {
    stubViewport(1000, 800)
    const { scrubber } = setup(bigCollection())
    const strip = document.createElement('div')
    stubStripRect(strip, { top: 100, left: 200, width: 50, height: 300 })
    scrubber.scrubberRef.value = strip
    const handle = document.createElement('div')

    scrubber.startHandleDrag(fakePointerEvent({ clientY: 100, clientX: 200, currentTarget: handle }))
    scrubber.moveHandleDrag(fakePointerEvent({ clientY: 150, clientX: 250, currentTarget: handle }))
    expect(scrubber.scrubberStyle.value).not.toEqual({})

    scrubber.resetHandlePosition()

    expect(scrubber.scrubberStyle.value).toEqual({})
    expect(localStorage.getItem('ludodex-scrubber-position')).toBeNull()
  })

  it('ignores a stray move event before the handle has actually been grabbed', () => {
    stubViewport(1000, 800)
    const { scrubber } = setup(bigCollection())
    const strip = document.createElement('div')
    stubStripRect(strip, { top: 100, left: 200, width: 50, height: 300 })
    scrubber.scrubberRef.value = strip
    const handle = document.createElement('div')

    scrubber.moveHandleDrag(fakePointerEvent({ clientY: 150, clientX: 250, currentTarget: handle }))

    expect(scrubber.scrubberStyle.value).toEqual({})
  })
})
