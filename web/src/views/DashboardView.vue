<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGamesStore, type UserGame } from '@/stores/games'
import { useToastStore } from '@/stores/toast'
import { useCollectionDensity } from '@/composables/useCollectionDensity'
import { useExpansionCounts } from '@/composables/useExpansionCounts'
import DensityToggle from '@/components/DensityToggle.vue'
import GameCard from '@/components/GameCard.vue'
import GameDetailModal from '@/components/GameDetailModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const games = useGamesStore()
const toast = useToastStore()
const { t } = useI18n()

// Which card's details modal (image/description) is open, if any - same
// component and trigger as the picker's own, kept independent of
// Editar/Quitar below rather than replacing either of them, since both
// stay the primary actions on this page.
const detailEntry = ref<UserGame | null>(null)

// Restoring the logged-in user from a stored token (e.g. after a reload)
// is App.vue's job now, not this view's - it needs to happen regardless
// of which page a reload/deep link lands on, not just this one.
onMounted(() => {
  if (!games.loaded) {
    games.fetchAll()
  }
})

// Client-side against the collection already loaded in the store, same as
// the picker's filters - a real collection can run into the hundreds of
// games (see the BGG CSV import), and finding one by scrolling stopped
// being practical well before that.
const search = ref('')

type TypeFilter = 'all' | 'base' | 'expansion'
const typeFilter = ref<TypeFilter>('all')

type SortCriterion = 'name' | 'rank' | 'year'

const sortCriterion = ref<SortCriterion>('name')
const sortOrder = ref<'asc' | 'desc'>('asc')

// BGG ranking doesn't exist for expansions - they're never ranked
// individually on BGG - so the option is disabled while this filter is
// active (see the select above). A criterion the select no longer allows
// picking can't stay selected underneath it, so it falls back to name here.
watch(typeFilter, (value) => {
  if (value === 'expansion' && sortCriterion.value === 'rank') {
    sortCriterion.value = 'name'
  }
})

function toggleSort() {
  sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
}

// The visible label always shows the *current* order; the aria-label/
// title describe what clicking will change it to instead (the same
// convention the old hardcoded "A → Z"/"Z → A" button already followed).
const sortToggleLabel = computed(() => {
  if (sortCriterion.value === 'rank') {
    return sortOrder.value === 'asc' ? '1 → N' : 'N → 1'
  }

  if (sortCriterion.value === 'year') {
    return sortOrder.value === 'asc' ? '▲' : '▼'
  }

  return sortOrder.value === 'asc' ? 'A → Z' : 'Z → A'
})

const sortToggleActionLabel = computed(() => {
  if (sortCriterion.value === 'rank') {
    return sortOrder.value === 'asc' ? t('dashboard.sortRankDesc') : t('dashboard.sortRankAsc')
  }

  if (sortCriterion.value === 'year') {
    return sortOrder.value === 'asc' ? t('dashboard.sortYearDesc') : t('dashboard.sortYearAsc')
  }

  return sortOrder.value === 'asc' ? t('dashboard.sortDesc') : t('dashboard.sortAsc')
})

const filtered = computed(() => {
  const query = search.value.trim().toLowerCase()

  const base = games.collection.filter((entry) => {
    if (query !== '' && !entry.game.name.toLowerCase().includes(query)) return false
    if (typeFilter.value === 'base' && entry.game.base_game_id !== null) return false
    if (typeFilter.value === 'expansion' && entry.game.base_game_id === null) return false

    return true
  })

  if (sortCriterion.value === 'rank') {
    return base.sort((a, b) => {
      const rankA = a.game.bgg_rank
      const rankB = b.game.bgg_rank

      // Games without a BGG rank (never linked, or too few votes on BGG
      // to place them) have no meaningful position - they always sink to
      // the bottom of the list regardless of sort direction, rather than
      // being treated as rank 0 (which would put them first) or Infinity
      // (which would flip to first on "worst first").
      if (rankA === null && rankB === null) return 0
      if (rankA === null) return 1
      if (rankB === null) return -1

      const cmp = rankA - rankB
      return sortOrder.value === 'asc' ? cmp : -cmp
    })
  }

  if (sortCriterion.value === 'year') {
    return base.sort((a, b) => {
      const yearA = a.game.year_published
      const yearB = b.game.year_published

      // Same reasoning as the rank sort above: a game with no known
      // publication year (never linked to BGG, or missing that field) has
      // no meaningful position on a timeline, so it always sinks to the
      // bottom regardless of direction.
      if (yearA === null && yearB === null) return 0
      if (yearA === null) return 1
      if (yearB === null) return -1

      const cmp = yearA - yearB
      return sortOrder.value === 'asc' ? cmp : -cmp
    })
  }

  return base.sort((a, b) => {
    const cmp = a.game.name.localeCompare(b.game.name)
    return sortOrder.value === 'asc' ? cmp : -cmp
  })
})

// Only meaningful while "Todos" is selected - switching typeFilter to
// "Juegos base"/"Expansiones" already turns the header count itself into
// exactly one of these two numbers, so showing this split alongside it
// there would just repeat what filtered.length already says. Counted
// within filtered (not the raw collection) so it stays consistent with
// an active search - if a search only matches 2 of the collection's 7
// expansions, this reflects the 2, not the 7.
const expansionsInFiltered = computed(
  () => filtered.value.filter((entry) => entry.game.base_game_id !== null).length,
)

const baseGamesInFiltered = computed(
  () => filtered.value.filter((entry) => entry.game.base_game_id === null).length,
)

const { density, toggle: toggleDensity } = useCollectionDensity()
const expansionCounts = useExpansionCounts(computed(() => games.collection))

// Prototype: an iOS-Contacts-style A-Z strip for jumping around a long,
// name-sorted collection instead of scrolling through it by hand. Only
// makes sense while sorted by name - "jump to L" has no coherent meaning
// against a list ordered by rank or year - and only worth showing once
// there's actually enough length to justify it.
const ALPHABET = ['#', ...'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('')]

const COMBINING_MARKS = /[̀-ͯ]/g

function normalizeLetter(name: string): string {
  const stripped = name.trim().normalize('NFD').replace(COMBINING_MARKS, '')
  const first = stripped.charAt(0).toUpperCase()
  return /[A-Z]/.test(first) ? first : '#'
}

const gamesListRef = ref<HTMLUListElement | null>(null)

const availableLetters = computed(() => {
  if (sortCriterion.value !== 'name') return new Set<string>()
  return new Set(filtered.value.map((entry) => normalizeLetter(entry.game.name)))
})

const showAzScrubber = computed(
  () => sortCriterion.value === 'name' && filtered.value.length > 12 && !detailEntry.value,
)

const scrubbing = ref(false)
const scrubLetter = ref<string | null>(null)

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

// A letter with no games in the current filter/search still needs a
// sensible target while dragging across it - silently doing nothing reads
// as the strip being broken, not as "no games here". Walks outward in both
// directions and jumps to whichever available letter is closest.
function nearestAvailableLetter(letter: string): string | null {
  if (availableLetters.value.has(letter)) return letter

  const index = ALPHABET.indexOf(letter)
  for (let offset = 1; offset < ALPHABET.length; offset++) {
    const before = ALPHABET[index - offset]
    const after = ALPHABET[index + offset]
    if (before && availableLetters.value.has(before)) return before
    if (after && availableLetters.value.has(after)) return after
  }

  return null
}

function jumpToLetter(letter: string) {
  const target = nearestAvailableLetter(letter)
  if (!target) return

  scrubLetter.value = target
  const el = gamesListRef.value?.querySelector<HTMLElement>(`[data-letter="${target}"]`)
  el?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// clientY -> letter is done by position within the strip's own bounding
// box, not by which child element the pointer happens to be over - that's
// what lets a single continuous drag sweep through every letter smoothly
// (via setPointerCapture below) instead of only reacting at the exact
// pixel of each <span>.
function letterAtPointer(clientY: number, container: HTMLElement): string {
  const rect = container.getBoundingClientRect()
  const ratio = Math.min(Math.max((clientY - rect.top) / rect.height, 0), 0.999)
  return ALPHABET[Math.floor(ratio * ALPHABET.length)]
}

function startScrub(event: PointerEvent) {
  const container = event.currentTarget as HTMLElement
  container.setPointerCapture(event.pointerId)
  hovering.value = true
  scrubbing.value = true
  jumpToLetter(letterAtPointer(event.clientY, container))
}

function moveScrub(event: PointerEvent) {
  if (!scrubbing.value) return
  jumpToLetter(letterAtPointer(event.clientY, event.currentTarget as HTMLElement))
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

async function onDelete(userGameId: string) {
  try {
    await games.deleteGame(userGameId)
    toast.show(t('dashboard.toastRemoved'))
  } catch {
    toast.show(t('dashboard.removeError'))
  }
}

// Same lightweight "click again to confirm" pattern as the edit page's own
// delete button - only one card can be armed at a time, so arming a new one
// (or the timeout firing) disarms whichever was armed before it.
const confirmingDeleteId = ref<string | null>(null)
let confirmingDeleteTimeout: ReturnType<typeof setTimeout> | undefined

function onDeleteClick(userGameId: string) {
  if (confirmingDeleteId.value !== userGameId) {
    clearTimeout(confirmingDeleteTimeout)
    confirmingDeleteId.value = userGameId
    confirmingDeleteTimeout = setTimeout(() => {
      confirmingDeleteId.value = null
    }, 4000)
    return
  }

  clearTimeout(confirmingDeleteTimeout)
  confirmingDeleteId.value = null
  onDelete(userGameId)
}

onUnmounted(() => clearTimeout(confirmingDeleteTimeout))

// Clearing the whole collection is permanent (no soft-delete/undo on the
// backend), so a plain "are you sure?" isn't enough friction - typing the
// exact count makes the user actually look at how many games they're
// about to lose instead of reflexively confirming a dialog. The count is
// snapshotted when the panel opens rather than read live from
// games.collection, so it can't silently change (or hit 0) while the
// confirmation is still open.
const clearConfirmOpen = ref(false)
const clearConfirmCount = ref(0)
const clearConfirmText = ref('')
const clearing = ref(false)

function openClearConfirm() {
  clearConfirmCount.value = games.collection.length
  clearConfirmText.value = ''
  clearConfirmOpen.value = true
}

function closeClearConfirm() {
  clearConfirmOpen.value = false
}

const clearConfirmMatches = computed(
  () => clearConfirmText.value.trim() === String(clearConfirmCount.value),
)

async function onClearCollection() {
  if (!clearConfirmMatches.value) {
    return
  }

  clearing.value = true

  try {
    await games.clearCollection()
    toast.show(t('dashboard.toastCleared'))
    clearConfirmOpen.value = false
  } catch {
    toast.show(t('dashboard.clearError'))
  } finally {
    clearing.value = false
  }
}
</script>

<template>
  <div>
    <div class="dashboard-toolbar">
      <div class="title-row">
        <h1>{{ $t('dashboard.title') }}</h1>
        <span v-if="games.loaded" class="count">
          <template v-if="typeFilter === 'all' && expansionsInFiltered > 0">
            {{
              $t('common.baseAndExpansionsCount', {
                base: baseGamesInFiltered,
                expansions: expansionsInFiltered,
              })
            }}
          </template>
          <template v-else-if="typeFilter === 'expansion'">
            {{ $t('common.expansionsCount', { count: filtered.length }) }}
          </template>
          <template v-else-if="typeFilter === 'base'">
            {{ $t('common.baseGamesCount', { count: filtered.length }) }}
          </template>
          <template v-else>{{ $t('common.gamesCount', { count: filtered.length }) }}</template>
        </span>
      </div>

      <template v-if="games.loaded && games.collection.length > 0">
        <div class="search-controls">
          <div class="search-group">
            <input
              v-model="search"
              type="search"
              :aria-label="$t('dashboard.searchLabel')"
              :placeholder="$t('dashboard.searchPlaceholder')"
            />
            <select v-model="typeFilter" :aria-label="$t('dashboard.typeFilterLabel')" class="type-filter">
              <option value="all">{{ $t('dashboard.typeAll') }}</option>
              <option value="base">{{ $t('dashboard.typeBase') }}</option>
              <option value="expansion">{{ $t('dashboard.typeExpansion') }}</option>
            </select>
          </div>
          <div class="sort-group">
            <!-- Transparent to the layout above 583px (each child its own
            grid item) - only becomes a real flex group below that, where
            all five controls need to sit together as one left-aligned
            block instead of each stretching/spacing independently across
            whatever column they happen to land in. -->
            <div class="sort-criterion-group">
              <select v-model="sortCriterion" :aria-label="$t('dashboard.sortByLabel')" class="sort-criterion">
                <option value="name">{{ $t('dashboard.sortByName') }}</option>
                <option value="rank" :disabled="typeFilter === 'expansion'">{{ $t('dashboard.sortByRank') }}</option>
                <option value="year">{{ $t('dashboard.sortByYear') }}</option>
              </select>
              <button
                type="button"
                class="btn sort-toggle"
                :aria-label="sortToggleActionLabel"
                :title="sortToggleActionLabel"
                @click="toggleSort"
              >
                {{ sortToggleLabel }}
              </button>

              <DensityToggle :density="density" @toggle="toggleDensity" />

              <!-- Same two buttons as .action-buttons below, shown instead
              of it (not alongside) once things get tight enough to need
              them icon-only - see the media query. Living here, right in
              .sort-group's own flex flow, is what lets them sit flush
              against the density toggle instead of stranded in a separate
              grid column that can only ever line up with .search-controls'
              widest wrapped line (.search-group's, not this one). -->
              <div class="inline-actions">
                <button
                  type="button"
                  class="btn btn-danger clear-library-btn"
                  :aria-label="$t('dashboard.clearLibrary')"
                  :title="$t('dashboard.clearLibrary')"
                  @click="openClearConfirm"
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6h16Z"
                    />
                    <line x1="10" y1="11" x2="10" y2="17" stroke-linecap="round" />
                    <line x1="14" y1="11" x2="14" y2="17" stroke-linecap="round" />
                  </svg>
                </button>
                <RouterLink
                  :to="{ name: 'add-game' }"
                  class="btn btn-primary add-game-btn"
                  :aria-label="$t('dashboard.addGame')"
                  :title="$t('dashboard.addGame')"
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="M12 5v14M5 12h14" />
                  </svg>
                </RouterLink>
              </div>
            </div>
          </div>
        </div>

        <div class="action-buttons">
          <button
            type="button"
            class="btn btn-danger clear-library-btn"
            :aria-label="$t('dashboard.clearLibrary')"
            :title="$t('dashboard.clearLibrary')"
            @click="openClearConfirm"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6h16Z"
              />
              <line x1="10" y1="11" x2="10" y2="17" stroke-linecap="round" />
              <line x1="14" y1="11" x2="14" y2="17" stroke-linecap="round" />
            </svg>
            <span class="btn-full-label">{{ $t('dashboard.clearLibrary') }}</span>
          </button>
          <RouterLink
            :to="{ name: 'add-game' }"
            class="btn btn-primary add-game-btn"
            :aria-label="$t('dashboard.addGame')"
            :title="$t('dashboard.addGame')"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" d="M12 5v14M5 12h14" />
            </svg>
            <span class="btn-full-label">{{ $t('dashboard.addGame') }}</span>
          </RouterLink>
        </div>
      </template>
    </div>

    <p v-if="games.loading" class="loading-state">
      <LoadingSpinner :size="28" />
      {{ $t('common.loadingCollection') }}
    </p>

    <p v-else-if="games.loaded && games.collection.length === 0" class="empty-state">
      {{ $t('dashboard.empty') }}<br />
      <RouterLink :to="{ name: 'add-game' }">{{ $t('dashboard.addFirst') }}</RouterLink>.
    </p>

    <template v-else-if="games.loaded">
      <div v-if="clearConfirmOpen" class="clear-confirm card" role="alertdialog">
        <p>{{ $t('dashboard.clearConfirmWarning', { count: clearConfirmCount }) }}</p>
        <label for="clear-confirm-input">{{
          $t('dashboard.clearConfirmInstructions', { count: clearConfirmCount })
        }}</label>
        <div class="clear-confirm-row">
          <input
            id="clear-confirm-input"
            v-model="clearConfirmText"
            type="text"
            :placeholder="$t('dashboard.clearConfirmPlaceholder', { count: clearConfirmCount })"
            autocomplete="off"
          />
          <button type="button" class="btn" :disabled="clearing" @click="closeClearConfirm">
            {{ $t('dashboard.cancel') }}
          </button>
          <button
            type="button"
            class="btn btn-danger"
            :disabled="!clearConfirmMatches || clearing"
            @click="onClearCollection"
          >
            {{ $t('dashboard.clearConfirmButton') }}
          </button>
        </div>
      </div>

      <p v-if="filtered.length === 0" class="empty-state">
        {{ $t('dashboard.noMatches') }}
      </p>
    </template>

    <ul ref="gamesListRef" class="games" :class="{ compact: density === 'compact' }">
      <li
        v-for="entry in filtered"
        :key="entry.id"
        class="game-card"
        :data-letter="normalizeLetter(entry.game.name)"
      >
        <GameCard
          :image-url="entry.game.image_url"
          :compact="density === 'compact'"
          :is-expansion="entry.game.base_game_id !== null"
        >
          <div class="game-card-header">
            <h2>{{ entry.game.name }}</h2>
            <button
              type="button"
              class="details-icon-button"
              :aria-label="$t('picker.viewDetails')"
              :title="$t('picker.viewDetails')"
              @click="detailEntry = entry"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"
                />
                <circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </button>
          </div>
          <div class="badge-row">
            <span class="badge" :class="entry.status === 'owned' ? 'badge-primary' : 'badge-accent'">
              <svg
                v-if="entry.status === 'owned'"
                class="badge-icon"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
              >
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 6 9 17l-5-5" />
              </svg>
              <svg
                v-else
                class="badge-icon"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"
                />
              </svg>
              {{ entry.status === 'owned' ? $t('dashboard.owned') : $t('dashboard.wishlist') }}
            </span>
            <span
              v-if="entry.game.bgg_id !== null && entry.game.base_game_id === null"
              class="badge badge-rank"
            >
              {{
                entry.game.bgg_rank !== null
                  ? $t('dashboard.rank', { rank: entry.game.bgg_rank })
                  : $t('dashboard.unranked')
              }}
            </span>
            <span v-if="expansionCounts[entry.game.id]" class="badge badge-expansion">
              {{ $t('dashboard.expansionsCount', { count: expansionCounts[entry.game.id] }) }}
            </span>
            <span
              v-if="entry.game.base_game_id !== null"
              class="badge badge-expansion"
              :title="$t('dashboard.expansionOf', { name: entry.game.base_game_name })"
            >
              {{ $t('dashboard.expansionOf', { name: entry.game.base_game_name }) }}
            </span>
          </div>
          <p
            v-if="
              entry.game.year_published ||
              entry.game.min_players ||
              entry.game.max_players ||
              entry.game.min_playtime_minutes ||
              entry.game.max_playtime_minutes
            "
            class="meta"
          >
            <span v-if="entry.game.year_published">{{ entry.game.year_published }}</span>
            <span v-if="entry.game.min_players || entry.game.max_players">
              {{ $t('dashboard.players', { min: entry.game.min_players, max: entry.game.max_players }) }}
            </span>
            <span v-if="entry.game.min_playtime_minutes || entry.game.max_playtime_minutes">
              {{
                $t('dashboard.duration', {
                  min: entry.game.min_playtime_minutes,
                  max: entry.game.max_playtime_minutes,
                })
              }}
            </span>
          </p>
          <div class="card-actions">
            <RouterLink
              :to="{ name: 'edit-game', params: { id: entry.id }, query: { from: 'dashboard' } }"
              class="btn"
            >
              {{ $t('dashboard.edit') }}
            </RouterLink>
            <button
              type="button"
              class="btn btn-danger"
              :class="{ 'btn-danger-confirm': confirmingDeleteId === entry.id }"
              @click="onDeleteClick(entry.id)"
            >
              {{ confirmingDeleteId === entry.id ? $t('dashboard.removeConfirm') : $t('dashboard.remove') }}
            </button>
          </div>
        </GameCard>
      </li>
    </ul>

    <GameDetailModal v-if="detailEntry" :game="detailEntry.game" @close="detailEntry = null" />

    <div
      v-if="showAzScrubber"
      class="az-scrubber"
      :class="{ 'az-scrubber-visible': hovering || scrubbing }"
      role="navigation"
      :aria-label="$t('dashboard.azScrubberLabel')"
      @pointerenter="onScrubberEnter"
      @pointerleave="onScrubberLeave"
      @pointerdown="startScrub"
      @pointermove="moveScrub"
      @pointerup="endScrub"
      @pointercancel="endScrub"
    >
      <span
        v-for="letter in ALPHABET"
        :key="letter"
        class="az-scrubber-letter"
        :class="{ 'az-scrubber-letter-available': availableLetters.has(letter) }"
      >
        {{ letter }}
      </span>
    </div>

    <div v-if="scrubbing && scrubLetter" class="az-scrubber-bubble">{{ scrubLetter }}</div>
  </div>
</template>

<style scoped>
/* A grid instead of two separate flex rows (title+clear-library-btn,
then search-controls+add-game-btn) so the layout can be reshuffled per
breakpoint below - "clear"/"add" sit each in their own row on a wide
screen, but need to end up sharing one row together once things get
narrow, which isn't something two independent flex containers can do
without literally moving markup between them. */
.dashboard-toolbar {
  display: grid;
  grid-template-columns: 1fr auto;
  grid-template-areas:
    'title clear'
    'search add';
  column-gap: var(--space-4);
  row-gap: var(--space-4);
  align-items: center;
  margin-bottom: var(--space-4);
}

.title-row {
  grid-area: title;
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
}

.count {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

/* Each of the two child groups below wraps as a single flex item, not
the controls inside it individually - without that, a control could end
up wrapping on its own regardless of which one it started next to,
splitting e.g. the sort select from its own toggle button rather than
moving them to the next line together. */
.search-controls {
  grid-area: search;
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
}

.search-group,
.sort-group {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

/* Transparent by default - .sort-criterion and .sort-toggle behave as
.sort-group's own direct flex children (or, from 740px down, direct
grid items in .dashboard-toolbar) exactly as if this wrapper wasn't
there. Only becomes a real box below 583px, where they need to move
and behave as one paired unit instead - see that media query. */
.sort-criterion-group {
  display: contents;
}

/* Hidden by default - only swapped in for .action-buttons below the
narrowest breakpoint further down, never shown alongside it. */
.inline-actions {
  display: none;
}

/* Every other control in this row already resists shrinking (the two
selects are flex-shrink: 0, the buttons have their own min-width) - this
one didn't, so as the row ran out of room it silently crushed the search
box down to an unusably narrow sliver instead of doing what flex-wrap is
there for. A firm floor makes the row wrap onto two clean lines instead,
once it truly doesn't fit. 171px rather than 180px - at the narrowest
real phones (366px wide, reported directly), that extra 9px pushed
.search-group's own row past the game cards' right edge below it,
noticeably out of alignment. */
.search-group input {
  max-width: 320px;
  min-width: 171px;
}

/* display: contents on a wide screen keeps these two as independent grid
items (their own grid-area below places them in separate rows) - the
@media override further down switches this to a real flex box instead,
which is what lets them share one row together once narrow. */
.action-buttons {
  display: contents;
}

.clear-library-btn {
  grid-area: clear;
}

.add-game-btn {
  grid-area: add;
}

/* Same fixed width for both - on a wide screen so the two rows' right
edges line up (the button up top and this one below read as one
vertical pair) instead of "Vaciar biblioteca" (the longer label, now
also carrying an icon) running wider than "+ Añadir juego" underneath
it; once they're side by side instead (below 850px), kept for the same
reason in spirit - shrinking only "+ Añadir juego" down to its own
shorter content while "Vaciar biblioteca" stayed put read as one button
being singled out rather than both simply not needing to shrink yet. */
.clear-library-btn,
.add-game-btn {
  min-width: 11rem;
  justify-content: center;
}

.clear-library-btn svg,
.add-game-btn svg {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
}

/* Roomy defaults for a wide screen - narrowed or stretched by the
various media queries below instead, once there's an actual tightness
problem to solve (or, past 740px, room to spare). Placed here, before
every media query that touches these same properties, on purpose: a
plain selector placed after a media query beats it in the cascade at
equal specificity regardless of which one is "more specific" in
intent, so this has to come first or every narrower override below
would lose to it instead of the other way around. */
.sort-criterion,
.type-filter {
  flex-shrink: 0;
  width: auto;
  max-width: 160px;
}

.type-filter {
  max-width: 190px;
}

.sort-toggle {
  flex-shrink: 0;
  white-space: nowrap;
}

/* Below this, "Vaciar biblioteca"/"+ Añadir juego" swap for the
icon-only pair living inside .sort-group instead (see the template
comment by .inline-actions for why a separate grid column for the same
job couldn't sit flush against the density toggle it's conceptually
joining). Used to be a two-stage transition (labelled buttons grouped
next to the title first, icon-only only once that got tight too), but
even grouped next to the title they already read as cramped at this
width - collapsed into the one threshold instead of two. Also where
.sort-criterion/.type-filter give up their roomy widths (see both
further down) - same media query, not a separate rule for what's
really the same threshold. */
@media (max-width: 850px) {
  /* .action-buttons (Vaciar/Añadir's own labelled-button column, 'clear'/
  'add' below) is display: none at this tier - without also collapsing
  the grid back to a single column here, that whole second column still
  reserves its own column-gap next to an now-empty auto track, leaving
  the density/clear/add cluster's own margin-left: auto stop short of
  the toolbar's actual right edge by that gap's width instead of
  reaching it (reported directly - the cluster looked "almost" flush,
  not quite). One column removes that dead strip entirely - title-row
  and search-controls each already only ever used the first column
  anyway. */
  .dashboard-toolbar {
    grid-template-columns: 1fr;
    grid-template-areas:
      'title'
      'search';
  }

  .action-buttons {
    display: none;
  }

  /* Temporary: keeps .search-group and .sort-group sharing one row
  instead of .sort-group wrapping onto its own - revisit once the rest
  of this layout is settled. */
  .search-controls {
    flex-wrap: nowrap;
  }

  /* flex: 1 (not width: 100%, which would force its own row even with
  nowrap above) claims whatever's left over next to .search-group on
  their shared row, giving margin-left: auto below room to push the
  density toggle and clear/add to the right edge without needing the
  whole row to itself. */
  .sort-group {
    flex: 1;
  }

  /* Pushed together with .inline-actions as one right-aligned cluster
  instead of staying grouped with the sort controls on the left - the
  density toggle reads as part of "how the cards display" alongside
  clear/add, not as another sort/filter control. density-toggle is a
  child component's own root element, hence :deep(). */
  :deep(.density-toggle) {
    margin-left: auto;
  }

  .inline-actions {
    display: flex;
    gap: var(--space-2);
  }

  .clear-library-btn,
  .add-game-btn {
    min-width: 0;
    padding: 0.5rem;
  }

  /* Same reasoning as .search-group input's own min-width above -
  .sort-group's row was landing a few pixels past the cards' right edge
  on the narrowest real phones. 126px cleared it at 375px, but not at
  an even narrower real 366px phone (9px less, reported directly) -
  118px is what actually clears both. */
  .sort-criterion {
    max-width: 118px;
  }

  /* Same alignment problem as .sort-criterion above, on .search-group's
  own row instead - .type-filter is the one asked to give up the
  difference there, same as .search-group input already does. */
  .type-filter {
    max-width: 151px;
  }
}

/* Below this, .search-group and .sort-group no longer fit on one line
even with the narrowed selects above - back to two lines, but instead
of wrapping inside .search-controls as a single flex item pair, each
one becomes its own grid row so clear/add can each line up with one
specific filter row (clear with the search row, add with the sort
row) rather than being stranded together off to one side. */
@media (max-width: 740px) {
  /* 5 columns: the two selects (type filter, sort criterion) are the
  flexible ones, splitting whatever's left over between them once
  sort-toggle/density/add's own natural widths are subtracted - 'search'
  spans the first 4 to reach all the way across to 'clear', which lines
  up with 'add' in the same last column. */
  .dashboard-toolbar {
    grid-template-columns: 1fr 1fr auto auto auto;
    grid-template-areas:
      'title     title      title   title    title'
      'search    search     search  search   clear'
      'type      criterion  toggle  density  add';
  }

  /* Exposes .search-group/.sort-group's own children as
  .dashboard-toolbar's direct grid items instead of being boxed inside
  one shared cell each - every one below needs its own grid-area to
  land in the right spot. */
  .search-controls {
    display: contents;
  }

  .search-group {
    display: contents;
  }

  .sort-group {
    display: contents;
  }

  /* Fills the grid cell's full width instead of capping out at the base
  rule's 320px - that cap existed to keep the input from stretching
  needlessly wide next to .type-filter on the same row, which no
  longer applies now that .type-filter has moved to the row below. */
  .search-group input {
    grid-area: search;
    max-width: none;
  }

  /* Same reasoning as the search input above - both selects stretch to
  fill their own (flexible) column instead of stopping at the 118px/
  151px caps tuned for the narrowest phones further up, which assumed
  a cramped shared row rather than each getting its own grid column.
  width: 100% is spelled out explicitly rather than relying on grid's
  own default item-stretch behaviour, which the shared base rule's own
  width: auto doesn't reliably trigger for a <select> the way it does
  for the search <input> above. */
  .type-filter {
    grid-area: type;
    width: 100%;
    max-width: none;
  }

  .sort-criterion {
    grid-area: criterion;
    width: 100%;
    max-width: none;
  }

  .sort-toggle {
    grid-area: toggle;
  }

  /* Its own column now, naturally adjacent to 'add' - no longer needs
  margin-left: auto to reach it. Child component's own root element,
  hence :deep(). */
  :deep(.density-toggle) {
    grid-area: density;
  }

  /* Back to the full labelled buttons instead of the icon-only pair,
  now that each filter row has its own matching button row alongside
  it - the icon-only swap further up was only ever about the labels
  not fitting next to the sort controls on a shared row, which doesn't
  apply to this layout. display: contents exposes clear/add as
  .dashboard-toolbar's own grid items too, landing in the 'clear'/'add'
  areas above via the base rule's grid-area assignment. */
  .action-buttons {
    display: contents;
  }

  .inline-actions {
    display: none;
  }

  /* Undoes the icon-only sizing from the 850px tier - back to full size
  with its label, same as before any of these breakpoints. padding is
  spelled out explicitly (matching .btn's own value) rather than
  revert, which falls through to the user-agent default instead of an
  earlier same-origin rule. */
  .clear-library-btn,
  .add-game-btn {
    min-width: 11rem;
    padding: 0.55rem 1rem;
  }
}

/* Below this, the labelled buttons from the 740px tier above no longer
fit their own column comfortably next to the two flexible selects -
back to icon-only, same as the 850px tier, landing in the same
'clear'/'add' grid cells the labelled ones use. The compound selectors
(.inline-actions .clear-library-btn, not just .clear-library-btn) are
what let this coexist with the 740px block's own rule for the same
classes - shared class names between the labelled and icon-only button
instances, so specificity (not source order) has to be what decides
which one's size/grid-area wins here. */
@media (max-width: 671px) {
  /* Clear moves down next to add instead of sitting alone on the search
  row - a 6th column (clear's own, right before add's) instead of the
  5 used further up. 'search' now spans the full row since there's no
  button left at its end to stop short of. */
  .dashboard-toolbar {
    grid-template-columns: 1fr 1fr auto auto auto auto;
    grid-template-areas:
      'title     title      title   title    title  title'
      'search    search     search  search   search search'
      'type      criterion  toggle  density  clear  add';
  }

  .action-buttons {
    display: none;
  }

  .inline-actions {
    display: contents;
  }

  /* min-width: 0 (not a fixed floor) is what the 850px tier's flexbox
  version needs to shrink past its own content, but here clear/add are
  grid items instead - 0 strips the track's normal content-based
  minimum protection entirely, letting the two flexible (1fr) columns
  next to it compress it arbitrarily small under space pressure rather
  than the size its own padding+icon actually need. 36px (padding
  0.5rem each side + the 16px icon, plus a hair of slack) keeps it a
  genuinely fixed size regardless of how tight the row gets. */
  .inline-actions .clear-library-btn,
  .inline-actions .add-game-btn {
    min-width: 36px;
    padding: 0.5rem;
  }

  /* The grid's own column-gap (16px, var(--space-4)) is wider than
  these three actually use at the 850px tier, where density/clear/add
  are flex siblings instead (gap: var(--space-3) between density and
  clear, var(--space-2) between clear and add) - negative margin pulls
  each one in by the difference so the spacing matches, without
  touching column-gap itself (which the other columns still rely on
  at its normal 16px). */
  .inline-actions .clear-library-btn {
    grid-area: clear;
    margin-left: calc(var(--space-3) - var(--space-4));
  }

  .inline-actions .add-game-btn {
    grid-area: add;
    margin-left: calc(var(--space-2) - var(--space-4));
  }

  /* Same reasoning as clear/add above - explicit floor instead of
  relying on the grid track's own automatic content-based minimum,
  which the density toggle's own width: 28px should already provide
  but isn't worth leaving implicit for a control meant to stay a fixed
  size. Child component's own root element, hence :deep(). */
  :deep(.density-toggle) {
    min-width: 28px;
  }
}

/* Below this, .sort-criterion's row is carrying too much (4 controls
plus clear/add) alongside .type-filter for it to stay comfortable -
.type-filter moves up to join the search row instead, docking at its
right edge the same way clear/add already dock at the end of theirs.

.sort-criterion and .sort-toggle stop being independent grid items and
become one compact paired unit (.sort-criterion-group) instead - both
had their own column up in the 740px tier, which worked while
.sort-criterion was deliberately stretched wide, but reads as the
toggle button stranded far from its own select once .sort-criterion
goes back to a natural width. A dedicated 1fr spacer column (unnamed,
just '.' in both rows) is what actually absorbs the leftover space
now, instead of an oversized column dragging space between the two. */
@media (max-width: 583px) {
  /* .search-group input gets its own dedicated column (not shared with
  any row2 control) sized to its own original cap, instead of the
  740px tier's max-width: none stretching it across the row -
  .type-filter is what stretches now, spanning the rest (including the
  1fr spacer column row2 uses for its own leftover space) so the two
  together still reach the row's full width between them. */
  .dashboard-toolbar {
    grid-template-columns: auto 1fr;
    grid-template-areas:
      'title     title'
      'search    type'
      'criterion criterion';
  }

  .search-group input {
    grid-area: search;
    max-width: 320px;
  }

  .type-filter {
    grid-area: type;
    width: 100%;
    max-width: none;
  }

  /* Density/clear/add moved into this same wrapper in the template
  (see the comment there) instead of staying independent grid items,
  so the whole row2 block - sort select, its toggle, density, clear,
  add - is one left-aligned unit spanning the full row, the same
  "together fill the row" pattern search+type use above. gap:
  var(--space-3) matches .sort-group's own original gap (the one used
  everywhere above 850px) now that this is a real flex row again, not
  grid columns needing column-gap compensated via negative margins. */
  .sort-criterion-group {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    grid-area: criterion;
  }

  /* flex: 1 grows to fill whatever the row has left after every other
  control's own natural width, same role .type-filter's width: 100%
  plays in its own (non-flex) row above. */
  .sort-criterion {
    flex: 1;
    width: auto;
    max-width: none;
  }

  /* Tighter gap between just these two, same as the 850px tier - real
  flex gap now, not the negative-margin compensation the 671px tier
  needs for its grid-column context. */
  .inline-actions {
    gap: var(--space-2);
  }

  .inline-actions .clear-library-btn,
  .inline-actions .add-game-btn {
    margin-left: 0;
  }
}

/* Below this, clear moves down to join add on row3 (to its left)
instead of sharing row2 with search/type - row2 carries only one
grid item now (.search-group itself, see below), not two or three
separate ones competing for column width. Density moves up to the
title row instead, docked flush against its right edge - it's the one
control that doesn't have an obvious row of its own to dock into once
clear joins add on row3.

.search-group stops being display: contents here (undoing the 740px
tier's own change) and gets a real flex row of its own instead,
spanning row2 as a single grid area across every column rather than
being broken up into the same per-column tracks as row1/row3. Row1
and row3 need their own columns for density/clear/add and to keep
'toggle' (the "A→Z"/"Z→A" label) from sharing a column with an icon
button and stretching it (auto columns size to the widest item
sharing that column across every row - a wide labelled button next to
an icon-only one in the same column pushed clear/add past the
toolbar's own right edge in an earlier attempt at this tier) - none
of that has anything to do with how wide the search input needs to
be, so rather than let row2 inherit whatever those columns add up to
(which briefly capped the input at ~144-172px, well short of the row's
own full width), .search-group gets the whole row to lay out
internally on its own terms. .search-controls itself stays display:
contents still, only .search-group changes, since .sort-group
(search-controls' other child) still needs to tunnel .inline-actions
and .density-toggle all the way up to this grid for clear/add/density
to land in row1/row3 at all. */
@media (max-width: 389px) {
  .dashboard-toolbar {
    grid-template-columns: 1fr auto auto auto;
    grid-template-areas:
      'title     title    title density'
      'search    search   search search'
      'criterion toggle   clear add';
  }

  /* Spans columns 1-3 (not just 1-2) - column 3 sits empty on this
  row anyway (only row3 uses it, for 'clear'), so title gets the
  benefit of that width too instead of it going to waste, giving "Tu
  colección" and its count enough room to stay on one line down to
  this tier's own narrowest supported width. flex-wrap stays as a
  safety net in case a translation ever needs more than that. */
  .title-row {
    flex-wrap: wrap;
  }

  .search-group {
    display: flex;
    flex-wrap: wrap;
    grid-area: search;
  }

  /* Both flex: 1 (not one fixed and the other absorbing 100% of
  whatever's left) so the two grow and shrink together as the row's
  own width changes, in proportion to their own basis, instead of one
  swelling while the other gets squeezed down to a fixed floor - that
  read as broken (the type filter visibly shrinking while the search
  input visibly grew) when only the input was flexible. min-width
  raised from 120px to 166px (asked for a smaller input, but not this
  small) - its own placeholder, "Buscar por nombre…", measures ~163px
  including padding at this font size, and anything narrower clips it
  mid-word with no ellipsis (plain input overflow, not text-overflow).
  Basis lowered from 171px to match, still comfortably above that
  floor. */
  .search-group input {
    flex: 1 1 166px;
    min-width: 166px;
  }

  /* min-width raised from 80px to 134px (asked for directly) - "Expansiones",
  its own longest option, needs ~129px unconstrained to render in full;
  anything narrower than that clips it to "Expansione" mid-word instead
  of wrapping or ellipsizing, since a native <select> just crops its
  selected value silently. 134px leaves a few px of real-device slack
  above that measured minimum, same margin this file already keeps
  elsewhere (.sort-criterion's 118px, .clear-confirm-row input's
  115px). Basis raised from 100px to match, so it starts from a
  comfortable width rather than depending on the min-width floor alone. */
  .type-filter {
    flex: 1 1 134px;
    width: auto;
    max-width: none;
    min-width: 134px;
  }

  .sort-criterion-group {
    display: contents;
  }

  /* min-width: 0 overrides the automatic content-based minimum a
  plain grid column otherwise protects (see column 1's own comment
  above) - without it, this select's own min-content (its longest
  option, "Ranking BGG") was what column 1 refused to shrink below,
  overflowing the toolbar at a real 360-366px phone once row3's other
  three columns (toggle/clear/add) are subtracted from what's left. A
  narrow select just clips its own text like any other, same trade-off
  already made for other controls at this tier. */
  .sort-criterion {
    grid-area: criterion;
    flex: initial;
    min-width: 0;
  }

  .sort-toggle {
    grid-area: toggle;
  }

  /* Shares column 4 with .add-game-btn below it (row3), not column 3
  where .clear-library-btn now lives alone - density is only 28px next
  to add's 36px, so without this it sits flush to the column's LEFT
  edge (its default alignment) instead of centered in the same block
  add fills completely on the row below. justify-self: center splits
  the leftover 8px evenly instead of pushing it all to one side.
  align-self: end (asked for directly, overriding .dashboard-toolbar's
  own align-items: center) - 'title' wraps onto two lines at this
  width (title, then the count below it), making its own grid row
  taller than a single line; centered across that full height put the
  toggle roughly between the two lines instead of level with the count
  text specifically. */
  :deep(.density-toggle) {
    grid-area: density;
    margin-left: 0;
    justify-self: center;
    align-self: end;
  }

  .inline-actions {
    display: contents;
  }

  .inline-actions .clear-library-btn {
    grid-area: clear;
    margin-left: 0;
  }

  .inline-actions .add-game-btn {
    grid-area: add;
    margin-left: 0;
  }
}

.clear-confirm {
  border-color: var(--color-danger);
  margin-bottom: var(--space-4);
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.clear-confirm-row {
  display: flex;
  gap: var(--space-2);
  flex-wrap: wrap;
}

.clear-confirm-row input {
  flex: 1;
  min-width: 160px;
}

/* 160px's floor left no room for Cancelar/Vaciar to share the row with
it at a real 366px phone (the input alone plus both buttons needs
~300px total, all this row has) - they wrapped Vaciar onto its own
second line instead. 115px is short enough to leave the ~179px both
buttons plus their gaps need. */
@media (max-width: 366px) {
  .clear-confirm-row input {
    min-width: 0;
    max-width: 115px;
  }
}

.games {
  list-style: none;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: var(--space-4);
}

.games.compact {
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: var(--space-2);
}

.games :deep(.game-card-header) {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.games :deep(h2) {
  font-size: 1.05rem;
  overflow-wrap: anywhere;
  flex: 1;
  min-width: 0;
}

/* Same size/style as the picker's own details button - same component,
same trigger, kept visually identical between the two pages rather than
matching whatever else happens to be on this card (Editar/Quitar below
are unrelated primary actions, not a style this needs to follow). */
.games :deep(.details-icon-button) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  flex-shrink: 0;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text-muted);
}

.games :deep(.details-icon-button:hover) {
  background: var(--color-surface-hover);
  color: var(--color-text);
}

.games :deep(.details-icon-button svg) {
  width: 16px;
  height: 16px;
}

/* Without this the badge row stretches to the scrim's full width, since
it's a direct child of a column flex container (align-items: stretch by
default) - the picker's badges look right because theirs sit inside their
own row flex wrapper (.tags) instead of directly in the scrim. */
.games :deep(.badge-row) {
  display: flex;
  align-self: flex-start;
  max-width: 100%;
  gap: var(--space-2);
  flex-wrap: wrap;
}

/* The status badge's usual tinted-transparent fill assumes a solid card
background - over an arbitrary photo it can lose all contrast against a
light patch of the image, so it needs a solid fill here instead. */
.games :deep(.badge-primary) {
  background: var(--color-primary);
  color: #fff;
}

.games :deep(.badge-accent) {
  background: var(--color-accent);
  color: #fff;
}

.games :deep(.badge-icon) {
  width: 12px;
  height: 12px;
  flex-shrink: 0;
  margin-right: 2px;
}

.games :deep(.badge-rank) {
  background: rgba(255, 255, 255, 0.2);
  color: #fff;
}

/* Same violet as the card's own left border (.game-cover.expansion), so
the badge reads as the text version of that same marker instead of an
unrelated color. */
.games :deep(.badge-expansion) {
  background: var(--color-expansion);
  color: #fff;
}

/* "Expansión de <nombre>" carries the base game's own name, which can run
long enough to push past the card's edge - the card clips it with
overflow: hidden, which without this cut the text off abruptly mid-word
instead of showing it's truncated. min-width: 0 overrides the flex item's
default content-based floor so it can actually shrink to make room for the
ellipsis instead of just ignoring max-width. text-overflow has no effect on
a flex container (the base .badge is display: inline-flex) - it only
applies to a block/inline-block box - hence the display override here. */
.games :deep(.badge-expansion) {
  display: inline-block;
  max-width: 100%;
  min-width: 0;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

/* The scrim is always dark regardless of theme (it sits over a photo, not
the app background), so these need their own fixed light colors instead of
the theme's usual muted-text variable. */
.games :deep(.meta) {
  display: flex;
  gap: var(--space-3);
  flex-wrap: wrap;
  font-size: 0.8rem;
  color: rgba(255, 255, 255, 0.75);
}

.games :deep(.card-actions) {
  display: flex;
  gap: var(--space-2);
  margin-top: var(--space-1);
}

/* Equal flex share rather than a fixed width tuned to one card size -
"Quitar" and its own armed label ("¿Seguro?") are different lengths, and
without this the button would shift width (shoving "Editar" sideways)
when it arms/reverts. flex:1 on both keeps them an even 50/50 split of
whatever room the row actually has, so unlike a fixed rem value it can't
run past the card's edge at a narrower card size (a 2-up compact grid on
a phone, say) that wasn't around to test against directly. */
.games :deep(.card-actions .btn) {
  flex: 1;
  min-width: 0;
  justify-content: center;
}

/* At the compact grid's narrower card width, "Editar"/"Quitar" side by
side at their normal padding/font-size don't fit - the card's own
overflow: hidden then clips "Quitar" instead of letting it wrap or
overflow visibly. */
.games.compact :deep(.card-actions .btn) {
  padding: 0.35rem 0.5rem;
  font-size: 0.8rem;
}

.games.compact :deep(.card-actions) {
  gap: var(--space-1);
}

/* Prototype - fixed to the viewport (not the page) so it stays reachable
regardless of scroll position, the whole point of a jump-navigation strip.
touch-action: none stops the browser's own scroll/pan gesture from
competing with the drag-to-scrub gesture on touch devices. */
/* right: 4px alone hugs the true browser edge - fine on mobile, where
#app fills the viewport anyway, but on a wide desktop window #app caps
out at 1024px and centers with margin: 0 auto, leaving a large empty
strip of bare background outside it; anchoring to the viewport's own
edge there stranded the scrubber far from the actual content column
(reported directly). (100vw - 1024px) / 2 is that outside margin (0
or negative below 1024px, hence the max() floor) - adding #app's own
right padding (var(--space-4)) reaches its content's real right edge,
then var(--space-2) pulls back inward from there for a small gap
instead of sitting flush against it. 1024px/var(--space-4) have to be
kept in sync with #app's own rule in main.css by hand - nothing ties
them together. */
.az-scrubber {
  position: fixed;
  right: max(4px, calc((100vw - 1024px) / 2 + var(--space-4) - var(--space-2)));
  top: 50%;
  transform: translateY(-50%);
  z-index: 20;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: center;
  /* Fluid, not stepped at a breakpoint (asked for directly - "reduce as
  the screen gets smaller", not "drop to a second fixed size below some
  width"). Tried the obvious "(100vw - 360px) / 664px" fluid-scale
  formula first (a length divided by a length, meant to produce a 0..1
  fraction) - this browser rejects any calc() that divides one length
  by another and drops the whole declaration, silently falling back to
  the property's initial value (verified directly: padding/height came
  back as literal empty strings from the parsed stylesheet rule, not
  just "looked wrong" on screen). Sidestepped entirely below by writing
  the interpolation's slope as a plain number attached to the vw unit
  itself (e.g. 21.0843vw is just a dimension, no division involved) and
  adding that to a plain px intercept - only ever adding two different
  absolute units together, which calc() has always supported. Both
  constants are pre-solved by hand for a straight line between
  (360px viewport, floor value) and (1024px viewport, the ceiling
  values used before this fluid pass) - 360px is the narrowest real
  phone this file has actual measurements for elsewhere (see the
  389px/366px tiers further up); 1024px matches #app's own max-width,
  so growth stops exactly where the content column itself stops
  growing. */
  height: min(70vh, clamp(380px, calc(304.097px + 21.0843vw), 520px));
  padding: clamp(0.25rem, calc(2.265px + 0.48193vw), 0.45rem)
    clamp(0.2rem, calc(1.465px + 0.48193vw), 0.4rem);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  box-shadow: var(--shadow-card);
  touch-action: none;
  user-select: none;
  cursor: pointer;
  opacity: 0.4;
  transition: opacity 0.15s ease;
}

/* Faint rather than fully invisible at rest (asked for directly - on a
real phone, opacity: 0 left no visible hint of where the strip even was,
so there was nothing to aim a first touch at). Full opacity on
hover/touch is the feedback that it landed - a growing scale was tried
here too, but reverted (asked for directly): scaling shifts every
letter's own screen position out from under whatever's still hovering
it, fighting the drag it's supposed to be giving feedback for. */
.az-scrubber-visible {
  opacity: 1;
}

/* Same fluid formula and anchors as .az-scrubber's own height/padding
above - all four properties need to shrink together in the same
proportion, not just the container, or the letters would end up
disproportionately large (or small) relative to the pill around them
at the in-between widths. */
.az-scrubber-letter {
  font-size: clamp(0.65rem, calc(7.7976px + 0.72289vw), 0.95rem);
  line-height: 1;
  color: var(--color-text-muted);
  opacity: 0.35;
}

/* Only letters with at least one matching game in the current
filter/search read as fully legible - a dimmed letter still works (drags
snap to the nearest available one, see nearestAvailableLetter), but
looking disabled sets the right expectation before the user commits to
that section. */
.az-scrubber-letter-available {
  color: var(--color-text);
  opacity: 1;
  font-weight: 600;
}

/* Mirrors iOS's own "big letter" callout while scrubbing - without it,
a letter this small under a fingertip is otherwise unreadable while
dragging, defeating the point of showing feedback at all. */
/* Same base offset as .az-scrubber's own right (kept in sync by hand,
same reasoning as its comment above) plus a fixed 2.5rem - the original
gap between the two when the strip was still hardcoded at right: 4px
(2.75rem - 4px), so the bubble keeps sitting the same distance outside
the strip regardless of where that ends up landing. */
.az-scrubber-bubble {
  position: fixed;
  right: calc(max(4px, calc((100vw - 1024px) / 2 + var(--space-4) - var(--space-2))) + 2.5rem);
  top: 50%;
  transform: translateY(-50%);
  z-index: 21;
  width: 3rem;
  height: 3rem;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  font-weight: 700;
  color: #fff;
  background: var(--color-primary);
  border-radius: var(--radius);
  pointer-events: none;
}
</style>
