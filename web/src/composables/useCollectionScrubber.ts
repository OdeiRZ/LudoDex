import { computed, ref, type ComputedRef, type Ref } from 'vue'
import type { UserGame } from '@/stores/games'

// An iOS-Contacts-style strip for jumping around a long collection
// instead of scrolling through it by hand - one set of buckets per sort
// criterion: the alphabet for name, the decades actually present for
// year, and a fixed set of rank tiers for BGG rank. Rank can't reuse
// "one slot per value" the way letters/decades do - ranks span a huge,
// mostly-unranked range - and unlike decades, its tiers are fixed rather
// than derived from the collection: "Top 100" needs to mean the same
// thing regardless of what's currently owned, where letting it drift
// with the data would make the same label mean a different cutoff over
// time. Shared between the collection and picker pages - both list
// UserGame entries with the same sort/filter shape, and the drag/
// hover/bucket machinery below is substantial enough that duplicating
// it verbatim in both views wasn't worth it.
export type ScrubberCriterion = 'name' | 'year' | 'rank'

const ALPHABET = ['#', ...'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('')]

const YEAR_UNKNOWN = '?'

const RANK_TIERS = ['≤100', '101-500', '501-1k', '1k-5k', '5k+']
const RANK_UNRANKED = '?'

const COMBINING_MARKS = /[̀-ͯ]/g

export function normalizeLetter(name: string): string {
  const stripped = name.trim().normalize('NFD').replace(COMBINING_MARKS, '')
  const first = stripped.charAt(0).toUpperCase()
  return /[A-Z]/.test(first) ? first : '#'
}

export function yearBucket(year: number | null): string {
  return year === null ? YEAR_UNKNOWN : String(Math.floor(year / 10) * 10)
}

export function rankBucket(rank: number | null): string {
  if (rank === null) return RANK_UNRANKED
  if (rank <= 100) return RANK_TIERS[0]
  if (rank <= 500) return RANK_TIERS[1]
  if (rank <= 1000) return RANK_TIERS[2]
  if (rank <= 5000) return RANK_TIERS[3]
  return RANK_TIERS[4]
}

export interface ScrubberLabels {
  name: string
  year: string
  rank: string
}

export interface UseCollectionScrubberOptions {
  sortCriterion: Ref<ScrubberCriterion>
  sortOrder: Ref<'asc' | 'desc'>
  /** The full pool the scrubber's own slots are computed from (e.g. the
   * whole collection, not just what a search currently matches) - kept
   * stable while the user types a search, same reasoning as ALPHABET
   * being a fixed constant rather than derived from whatever happens to
   * be currently visible. */
  pool: ComputedRef<UserGame[]>
  /** Currently visible entries, for which buckets read as available
   * (vs. dimmed) and for the >12 threshold that decides whether the
   * scrubber shows at all. */
  filtered: ComputedRef<UserGame[]>
  listRef: Ref<HTMLElement | null>
  /** Force-hides the scrubber regardless of the other conditions - e.g.
   * a detail modal open on top of the page. */
  hidden: ComputedRef<boolean>
  labels: ComputedRef<ScrubberLabels>
}

export function useCollectionScrubber(options: UseCollectionScrubberOptions) {
  const { sortCriterion, sortOrder, pool, filtered, listRef, hidden, labels } = options

  // Sorted oldest-first; this is the scrubber's own canonical order for
  // year mode, the same role ALPHABET plays for name mode.
  const yearDecades = computed(() => {
    const decades = new Set<number>()
    for (const entry of pool.value) {
      if (entry.game.year_published !== null) {
        decades.add(Math.floor(entry.game.year_published / 10) * 10)
      }
    }
    return [...decades].sort((a, b) => a - b).map(String)
  })

  // Only a real slot at all once at least one game actually needs it -
  // otherwise it's a dead, permanently-dimmed entry with nothing to ever
  // jump to, cluttering the strip for no reason (asked about directly,
  // after the collection's one yearless game got a year and the "?" kept
  // showing with nothing behind it). Same idea applies to the rank
  // scrubber's own unranked bucket, even though in practice it'll almost
  // always have games in it - most family/local games never get a BGG
  // rank at all.
  const hasUnknownYear = computed(() => pool.value.some((entry) => entry.game.year_published === null))
  const hasUnrankedGame = computed(() => pool.value.some((entry) => entry.game.bgg_rank === null))

  const yearBuckets = computed(() =>
    hasUnknownYear.value ? [...yearDecades.value, YEAR_UNKNOWN] : yearDecades.value,
  )
  const rankBuckets = computed(() => (hasUnrankedGame.value ? [...RANK_TIERS, RANK_UNRANKED] : RANK_TIERS))

  const scrubberBuckets = computed<string[]>(() => {
    if (sortCriterion.value === 'year') return yearBuckets.value
    if (sortCriterion.value === 'rank') return rankBuckets.value
    return ALPHABET
  })

  function bucketForEntry(entry: UserGame): string {
    if (sortCriterion.value === 'year') return yearBucket(entry.game.year_published)
    if (sortCriterion.value === 'rank') return rankBucket(entry.game.bgg_rank)
    return normalizeLetter(entry.game.name)
  }

  const availableBuckets = computed(() => {
    if (sortCriterion.value !== 'name' && sortCriterion.value !== 'year' && sortCriterion.value !== 'rank') {
      return new Set<string>()
    }
    return new Set(filtered.value.map((entry) => bucketForEntry(entry)))
  })

  const scrubberAriaLabel = computed(() => {
    if (sortCriterion.value === 'year') return labels.value.year
    if (sortCriterion.value === 'rank') return labels.value.rank
    return labels.value.name
  })

  const showScrubber = computed(
    () =>
      (sortCriterion.value === 'name' || sortCriterion.value === 'year' || sortCriterion.value === 'rank') &&
      filtered.value.length > 12 &&
      !hidden.value,
  )

  // The strip's own visual order needs to follow whichever direction the
  // list itself is actually sorted in - otherwise, sorted Z→A (or
  // newest-first, or worst-rank-first), the top of the strip would jump
  // to the very bottom of the list instead of its top, and vice versa at
  // the bottom. Games with no known year/rank always sink to the very
  // end of the list regardless of direction (each view's own year/rank
  // sort comparator does this), so unlike the alphabet's "#" bucket -
  // which does reverse along with the rest, since non-letter names are
  // ordered normally by localeCompare - the "unknown" slot in either of
  // the other two never moves from the end. nearestAvailableBucket below
  // still walks scrubberBuckets' own canonical (always oldest/
  // best-ranked/A first) order, since "nearest available bucket" is
  // about the buckets' own adjacency, not about where either one
  // happens to sit on screen.
  const displayBuckets = computed(() => {
    if (sortCriterion.value === 'year') {
      const decades = sortOrder.value === 'asc' ? yearDecades.value : [...yearDecades.value].reverse()
      return hasUnknownYear.value ? [...decades, YEAR_UNKNOWN] : decades
    }

    if (sortCriterion.value === 'rank') {
      const tiers = sortOrder.value === 'asc' ? RANK_TIERS : [...RANK_TIERS].reverse()
      return hasUnrankedGame.value ? [...tiers, RANK_UNRANKED] : tiers
    }

    return sortOrder.value === 'asc' ? ALPHABET : [...ALPHABET].reverse()
  })

  const scrubbing = ref(false)
  const scrubLetter = ref<string | null>(null)
  // Viewport Y the bubble should sit at while dragging - tracks the
  // pointer itself (clamped to the strip's own bounds, since drag events
  // keep arriving even once a finger sweeps past the strip's actual top/
  // bottom edge) rather than staying fixed at the strip's center, so the
  // callout stays right next to whatever the pointer is currently over
  // (asked for directly).
  const scrubBubbleTop = ref(0)

  // Stays fully transparent until something actually reveals it - a mouse
  // hovering over its own (narrow) hit area counts as "approaching" it, but
  // touch has no equivalent proximity signal before actual contact, so on
  // touch it only appears the moment a finger lands on it (see the
  // pointerType check in endScrub below, which is the counterpart for
  // hiding it again once that finger lifts).
  const hovering = ref(false)

  function onScrubberEnter() {
    hovering.value = true
  }

  function onScrubberLeave() {
    hovering.value = false
  }

  // A bucket with no games in the current filter/search still needs a
  // sensible target while dragging across it - silently doing nothing reads
  // as the strip being broken, not as "nothing here". Walks outward in both
  // directions and jumps to whichever available bucket is closest.
  function nearestAvailableBucket(bucket: string): string | null {
    if (availableBuckets.value.has(bucket)) return bucket

    const buckets = scrubberBuckets.value
    const index = buckets.indexOf(bucket)
    for (let offset = 1; offset < buckets.length; offset++) {
      const before = buckets[index - offset]
      const after = buckets[index + offset]
      if (before && availableBuckets.value.has(before)) return before
      if (after && availableBuckets.value.has(after)) return after
    }

    return null
  }

  function jumpToBucket(bucket: string) {
    const target = nearestAvailableBucket(bucket)
    if (!target) return

    scrubLetter.value = target
    const attr =
      sortCriterion.value === 'year'
        ? 'data-year-bucket'
        : sortCriterion.value === 'rank'
          ? 'data-rank-bucket'
          : 'data-letter'
    const el = listRef.value?.querySelector<HTMLElement>(`[${attr}="${target}"]`)
    el?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  // clientY -> bucket is done by position within the strip's own bounding
  // box, not by which child element the pointer happens to be over - that's
  // what lets a single continuous drag sweep through every bucket smoothly
  // (via setPointerCapture below) instead of only reacting at the exact
  // pixel of each <span>.
  function bucketAtPointer(clientY: number, container: HTMLElement): string {
    const rect = container.getBoundingClientRect()
    const ratio = Math.min(Math.max((clientY - rect.top) / rect.height, 0), 0.999)
    return displayBuckets.value[Math.floor(ratio * displayBuckets.value.length)]
  }

  function updateBubblePosition(clientY: number, container: HTMLElement) {
    const rect = container.getBoundingClientRect()
    scrubBubbleTop.value = Math.min(Math.max(clientY, rect.top), rect.bottom)
  }

  function startScrub(event: PointerEvent) {
    const container = event.currentTarget as HTMLElement
    container.setPointerCapture(event.pointerId)
    hovering.value = true
    scrubbing.value = true
    updateBubblePosition(event.clientY, container)
    jumpToBucket(bucketAtPointer(event.clientY, container))
  }

  function moveScrub(event: PointerEvent) {
    if (!scrubbing.value) return
    const container = event.currentTarget as HTMLElement
    updateBubblePosition(event.clientY, container)
    jumpToBucket(bucketAtPointer(event.clientY, container))
  }

  // A real mouse keeps sending its own pointerenter/pointerleave once
  // capture ends, so its hover state doesn't need forcing here - it'll
  // naturally go transparent once the cursor actually leaves. Touch/pen
  // have no such lingering hover state to fall back on, so without this
  // they'd stay visible after the finger lifts until some other page
  // interaction happened to trigger pointerleave.
  function endScrub(event: PointerEvent) {
    scrubbing.value = false
    scrubLetter.value = null
    if (event.pointerType !== 'mouse') {
      hovering.value = false
    }
  }

  return {
    showScrubber,
    displayBuckets,
    availableBuckets,
    scrubbing,
    scrubLetter,
    scrubBubbleTop,
    hovering,
    scrubberAriaLabel,
    onScrubberEnter,
    onScrubberLeave,
    startScrub,
    moveScrub,
    endScrub,
  }
}
