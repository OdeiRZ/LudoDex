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

const { density, toggle: toggleDensity } = useCollectionDensity()
const expansionCounts = useExpansionCounts(computed(() => games.collection))

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
        <span v-if="games.loaded" class="count">{{
          $t('common.gamesCount', { count: filtered.length })
        }}</span>
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

            <!-- Same two buttons as .action-buttons below, shown instead of
            it (not alongside) once things get tight enough to need them
            icon-only - see the media query. Living here, right in
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

    <ul class="games" :class="{ compact: density === 'compact' }">
      <li v-for="entry in filtered" :key="entry.id" class="game-card">
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
it; once they're side by side instead (below 880px), kept for the same
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
@media (max-width: 880px) {
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
even with the narrowed selects above - back to wrapping .sort-group
(with the density toggle/clear/add cluster it carries) onto its own
second line instead of the 880px tier's forced nowrap. */
@media (max-width: 740px) {
  .search-controls {
    flex-wrap: wrap;
  }

  .sort-group {
    flex: 1 1 100%;
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

/* Roomy defaults for a wide screen - narrowed to 118px/151px inside
the max-width: 880px block above instead, once there's actually a real
tightness problem to solve. */
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
</style>
