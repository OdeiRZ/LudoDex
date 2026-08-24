<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePlaysStore, type Play } from '@/stores/plays'
import { useToastStore } from '@/stores/toast'
import { FALLBACK_ICON_URL } from '@/lib/assets'
import GameDetailModal from '@/components/GameDetailModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const plays = usePlaysStore()
const toast = useToastStore()
const { t } = useI18n()

// Which play's own game detail is open, if any - same "one at a time"
// pattern as the picker/dashboard's own detailEntry.
const detailGame = ref<Play['game'] | null>(null)

// play.game isn't part of games.collection (see PlayResource/DetailGame's
// own comments), so the store action's own side effect of updating a
// matching collection entry never reaches it - without this, translating
// a game here would show the result while the modal stayed open, but
// closing and reopening the same play's modal later would show English
// again despite the DB already having the translation saved. Mutating
// detailGame.value here (this component's own ref, not a prop) is what
// makes it stick: it's the exact same object plays.entries holds, not a
// copy, so the list itself picks it up too, not just this one open modal.
function onDetailGameTranslated(descriptionEs: string | null) {
  if (detailGame.value) {
    detailGame.value.description_es = descriptionEs
  }
}

// Local, immediate-feedback copy of the search box's own value - the
// store's own plays.search only updates (and triggers a fetch) after the
// debounce below, so typing itself is never blocked waiting on a
// request.
const searchInput = ref('')
let searchDebounce: ReturnType<typeof setTimeout> | undefined

function onSearchInput() {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => plays.setSearch(searchInput.value), 300)
}

onUnmounted(() => clearTimeout(searchDebounce))

function loadMore() {
  plays.fetchPage(plays.currentPage + 1)
}

// A shortcut for the same "Partidas" import form ImportBggView already has,
// so re-checking BGG for new plays doesn't require leaving this page - the
// username is asked for again each time (never prefilled) rather than
// stored anywhere, matching how the standalone import form itself behaves.
const reimportPanelOpen = ref(false)
const reimportUsername = ref('')
const reimportError = ref<string | null>(null)
const reimporting = ref(false)
// Off by default (the fast, common-case path) - only needed for a play
// backfilled on BGG with an old date, which the incremental reimport
// above can never reach on its own (see BggPlaysImportService's own
// doc comment on why - reported directly).
const reimportFull = ref(false)

function toggleReimportPanel() {
  if (reimportPanelOpen.value) {
    reimportPanelOpen.value = false
    return
  }

  reimportUsername.value = ''
  reimportError.value = null
  reimportFull.value = false
  reimportPanelOpen.value = true
}

async function onReimportConfirm() {
  reimporting.value = true
  reimportError.value = null

  try {
    const result = await plays.importPlays(reimportUsername.value, reimportFull.value)
    await Promise.all([plays.fetchPage(1), plays.fetchStats()])
    reimportPanelOpen.value = false
    toast.show(
      t('importBgg.playsCompleted', { count: result.imported_count }, result.imported_count),
    )
  } catch {
    reimportError.value = t('importBgg.playsGenericError')
  } finally {
    reimporting.value = false
  }
}

// "n/d" when nothing has a known duration (a fresh import from an
// account that never logs play length, say) rather than "0 min" - total
// only ever sums rows that do have one (see PlayController::stats' own
// docblock), so 0 here is ambiguous between "genuinely nothing" and
// "unknown" without this flag.
const totalTimeLabel = computed(() => {
  const stats = plays.stats
  if (!stats || stats.duration_known_plays === 0) {
    return t('plays.statsNoDuration')
  }

  const hours = Math.floor(stats.total_minutes / 60)
  const minutes = stats.total_minutes % 60

  if (hours === 0) {
    return t('plays.duration', { minutes })
  }

  return minutes === 0
    ? t('plays.statsHoursOnly', { hours })
    : t('plays.statsHoursMinutes', { hours, minutes })
})

onMounted(() => {
  if (!plays.loaded) {
    plays.fetchPage(1)
  }

  if (!plays.stats) {
    plays.fetchStats()
  }
})
</script>

<template>
  <div class="plays">
    <div class="plays-header">
      <h1>{{ $t('plays.title') }}</h1>
      <!-- Same visibility rule as the search box below (see its own
      comment) - reimporting only makes sense once something's already
      been imported; before that, the empty state's own link to the
      standalone import form is the way in. -->
      <button
        v-if="plays.loaded && (plays.entries.length > 0 || plays.search)"
        type="button"
        class="btn reimport-btn"
        :aria-label="$t('plays.reimportButton')"
        :title="$t('plays.reimportButton')"
        :aria-expanded="reimportPanelOpen"
        @click="toggleReimportPanel"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <polyline points="23 4 23 10 17 10" stroke-linecap="round" stroke-linejoin="round" />
          <polyline points="1 20 1 14 7 14" stroke-linecap="round" stroke-linejoin="round" />
          <path
            d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </button>
    </div>

    <div v-if="reimportPanelOpen" class="reimport-panel card" role="dialog">
      <p>{{ $t('plays.reimportWarning') }}</p>
      <label for="reimport-username">{{ $t('importBgg.username') }}</label>
      <div class="reimport-row">
        <input
          id="reimport-username"
          v-model="reimportUsername"
          type="text"
          autocomplete="off"
          :disabled="reimporting"
        />
        <!-- Hidden rather than just disabled once reimporting starts
        (reported directly): "Importando..." is longer than "Importar",
        and .reimport-row input's own flex: 1 had to give up that extra
        width to the growing button, clipping the typed username -
        Cancelar is already irrelevant at this point (nothing left to
        back out of), so removing it instead of graying it out gives
        the input that room back instead of just wasting it on a button
        nobody can use anyway. -->
        <button v-if="!reimporting" type="button" class="btn" @click="toggleReimportPanel">
          {{ $t('dashboard.cancel') }}
        </button>
        <button
          type="button"
          class="btn btn-primary"
          :disabled="!reimportUsername.trim() || reimporting"
          @click="onReimportConfirm"
        >
          {{ reimporting ? $t('importBgg.playsSubmitting') : $t('plays.reimportSubmit') }}
        </button>
      </div>
      <label class="reimport-full-label">
        <input v-model="reimportFull" type="checkbox" :disabled="reimporting" />
        {{ $t('importBgg.playsFullLabel') }}
      </label>
      <p class="hint">{{ $t('importBgg.playsFullHint') }}</p>
      <p v-if="reimporting" class="alert alert-info reimport-submitting">
        <LoadingSpinner :size="28" />
        {{ $t('importBgg.dontCloseTab') }}
      </p>
      <p v-if="reimportError" role="alert" class="alert alert-error">{{ reimportError }}</p>
    </div>

    <!-- Aggregated server-side over the full history (see
    plays.fetchStats' own comment), not derived from plays.entries, which
    only ever holds whatever pages have been paged through so far. Hidden
    until there's at least one play, same as the search box below. -->
    <div v-if="plays.stats && plays.stats.total_plays > 0" class="stats-bar">
      <div class="stat-tile">
        <span class="stat-value">{{ plays.stats.total_plays }}</span>
        <span class="stat-label">{{ $t('plays.statsTotalPlays') }}</span>
      </div>
      <div class="stat-tile">
        <span class="stat-value">{{ plays.stats.distinct_games }}</span>
        <span class="stat-label">{{ $t('plays.statsDistinctGames') }}</span>
      </div>
      <div class="stat-tile">
        <span class="stat-value">{{ totalTimeLabel }}</span>
        <span class="stat-label">{{ $t('plays.statsTotalTime') }}</span>
      </div>
    </div>

    <div v-if="plays.stats && plays.stats.top_played.length > 0" class="top-played">
      <h2 class="top-played-title">{{ $t('plays.statsMostPlayed') }}</h2>
      <ol class="top-played-list">
        <li
          v-for="(entry, index) in plays.stats.top_played"
          :key="entry.game.id"
          class="top-played-item"
        >
          <div class="top-played-row">
            <span class="top-played-rank">{{ index + 1 }}</span>
            <span class="top-played-name">{{ entry.game.name }}</span>
            <span class="top-played-count">
              {{ $t('plays.statsMostPlayedCount', { count: entry.count }, entry.count) }}
            </span>
          </div>
          <!-- Alongside the rolled-up total rather than instead of it
          (kept after trying it out live against a real multi-expansion
          play history): only present when more than one specific game
          (the base and/or one of its expansions) contributed to this
          entry's total - see the backend's own comment on why a
          single-contributor entry never gets one. -->
          <ul v-if="entry.breakdown" class="top-played-breakdown">
            <li v-for="item in entry.breakdown" :key="item.game.id" class="top-played-breakdown-row">
              <span class="top-played-breakdown-name">{{ item.game.name }}</span>
              <span class="top-played-breakdown-count">
                {{ $t('plays.statsMostPlayedCount', { count: item.count }, item.count) }}
              </span>
            </li>
          </ul>
        </li>
      </ol>
    </div>

    <!-- Hidden only for the true "nothing imported yet" empty state below -
    plays.search is what tells the two apart, since an empty entries list
    means either that or a search with no matches (which keeps the box,
    so it can be cleared/changed). -->
    <div v-if="plays.loaded && (plays.entries.length > 0 || plays.search)" class="search-field">
      <label for="plays-search">{{ $t('plays.searchLabel') }}</label>
      <input
        id="plays-search"
        v-model="searchInput"
        type="search"
        :placeholder="$t('plays.searchPlaceholder')"
        @input="onSearchInput"
      />
    </div>

    <!-- Only for the very first fetch, before plays.loaded flips true -
    "load more" (later pages) also sets plays.loading, but shouldn't hide
    the list already on screen while it fetches, so this checks !loaded
    rather than plays.loading alone; that later loading state is covered
    by the load-more button's own disabled+label further down instead. -->
    <p v-if="!plays.loaded" class="loading-state">
      <LoadingSpinner :size="36" />
      {{ $t('plays.loading') }}
    </p>

    <p v-else-if="plays.entries.length === 0 && !plays.search" class="empty-state">
      {{ $t('plays.empty') }}<br />
      <RouterLink :to="{ name: 'import-bgg', query: { tab: 'plays' } }">{{
        $t('plays.importLink')
      }}</RouterLink>.
    </p>

    <p v-else-if="plays.entries.length === 0" class="empty-state">
      {{ $t('plays.noMatches') }}
    </p>

    <ul v-else class="play-list">
      <li v-for="(play, index) in plays.entries" :key="play.id" class="play-row">
        <span class="play-index">{{ index + 1 }}</span>

        <button
          type="button"
          class="play-cover-button"
          :aria-label="$t('picker.viewDetails')"
          :title="$t('picker.viewDetails')"
          @click="detailGame = play.game"
        >
          <img
            v-if="play.game.image_url"
            :src="play.game.image_url"
            alt=""
            class="play-cover"
          />
          <img v-else :src="FALLBACK_ICON_URL" alt="" class="play-cover play-cover-fallback" />
        </button>

        <div class="play-info">
          <span class="play-name">{{ play.game.name }}</span>
          <span v-if="play.game.base_game_name" class="play-base-game">
            {{ $t('dashboard.expansionOf', { name: play.game.base_game_name }) }}
          </span>
          <span class="play-meta">
            {{ play.played_at }}
            <template v-if="play.duration_minutes">
              · {{ $t('plays.duration', { minutes: play.duration_minutes }) }}
            </template>
            <template v-if="play.quantity > 1"> · ×{{ play.quantity }}</template>
          </span>
        </div>
      </li>
    </ul>

    <button
      v-if="plays.loaded && plays.currentPage < plays.lastPage"
      type="button"
      class="btn load-more"
      :disabled="plays.loading"
      @click="loadMore"
    >
      {{ $t('plays.loadMore') }}
    </button>

    <GameDetailModal
      v-if="detailGame"
      :game="detailGame"
      @close="detailGame = null"
      @translated="onDetailGameTranslated"
    />
  </div>
</template>

<style scoped>
.plays {
  max-width: 560px;
  margin: 0 auto;
}

h1 {
  margin: 0;
}

.plays-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  margin-bottom: var(--space-4);
}

.stats-bar {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
}

.stat-tile {
  flex: 1 1 auto;
  min-width: 7rem;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: var(--space-2) var(--space-3);
  background: var(--color-surface);
  border-radius: var(--radius);
}

.stat-value {
  font-weight: 600;
  font-size: 1.1rem;
}

.stat-label {
  color: var(--color-text-muted);
  font-size: 0.8rem;
}

.top-played {
  margin-bottom: var(--space-4);
}

.top-played-title {
  font-size: 0.9rem;
  color: var(--color-text-muted);
  margin-bottom: var(--space-2);
}

.top-played-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

/* Background/radius live here rather than on .top-played-row (asked for
directly, once the breakdown list needed to sit inside the same card
instead of floating below it on the bare page background) - .top-played-row
itself is just the primary line's own flex layout now. */
.top-played-item {
  background: var(--color-surface);
  border-radius: var(--radius);
}

.top-played-row {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-1) var(--space-3);
}

.top-played-rank {
  flex-shrink: 0;
  width: 1rem;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.top-played-name {
  flex: 1;
  min-width: 0;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.top-played-count {
  flex-shrink: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.top-played-breakdown {
  list-style: none;
  margin: 0;
  padding: 0 var(--space-3) var(--space-1);
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.top-played-breakdown-row {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  /* Lines up under .top-played-name, past .top-played-rank's own width
  and gap, so the breakdown reads as nested under the game it belongs
  to rather than starting flush with the row above it. */
  padding-left: calc(1rem + var(--space-3));
  color: var(--color-text-muted);
  font-size: 0.78rem;
}

.top-played-breakdown-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.top-played-breakdown-count {
  flex-shrink: 0;
}

.search-field {
  margin-bottom: var(--space-4);
}

.reimport-btn {
  flex-shrink: 0;
  padding: var(--space-2);
}

.reimport-btn svg {
  width: 16px;
  height: 16px;
}

.reimport-panel {
  margin-bottom: var(--space-4);
}

.reimport-panel label {
  display: block;
  margin-top: var(--space-2);
}

.reimport-row {
  display: flex;
  gap: var(--space-2);
  margin-top: var(--space-1);
}

.reimport-row input {
  flex: 1;
  min-width: 0;
}

/* .reimport-panel's own "label { display: block }" rule (above) has
higher specificity (class + element vs. this class alone) and would
otherwise win over this - qualified with the same parent to match it. */
.reimport-panel .reimport-full-label {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

/* The global input { width: 100% } rule (meant for text inputs) is
normally countered by a fieldset-scoped override elsewhere in the app -
this checkbox isn't inside a fieldset, so without this it stretches to
the full row width instead of its own natural checkbox size. */
.reimport-full-label input[type='checkbox'] {
  width: auto;
  flex-shrink: 0;
}

.hint {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.reimport-submitting {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-top: var(--space-2);
}

.play-list {
  list-style: none;
  margin: 0 0 var(--space-4);
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.play-row {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-2) var(--space-3);
  background: var(--color-surface);
  border-radius: var(--radius);
}

/* A running count across every loaded page (asked for directly), not
per-page - "load more" appends to the same plays.entries array rather
than replacing it, so the v-for's own index already counts continuously
without needing separate state to track it. text-align: left (asked
for directly, down from the original right) hugs the row's own left
edge instead of the cover. width: 0.75rem (asked for directly, down
from 1.5rem) keeps a 1-2 digit index snug against that edge too -
narrower than a 3-digit index's own natural width, so past 99 plays
the number starts overflowing this fixed box rather than the row still
lining every cover up regardless of digit count the way the original
1.5rem did. */
.play-index {
  width: 0.75rem;
  flex-shrink: 0;
  text-align: left;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

/* No border/background/padding of its own beyond resetting the button
defaults - visually this should read as just the cover image sitting in
the row, same as before it became clickable, not as a distinct button. */
.play-cover-button {
  display: block;
  padding: 0;
  border: none;
  background: none;
  border-radius: var(--radius);
  flex-shrink: 0;
  cursor: pointer;
}

/* 56px (up from 40px, asked for directly) - same size already used for
compact-mode covers elsewhere in the app (GameCard.vue), rather than a
number picked just for this row. */
/* display: block is the fix - an <img> is inline by default, so without
this it sits on its container's own text baseline, leaving a ~6px
descender gap below it (the classic "mystery space under an image"
CSS quirk) - .play-cover-button ended up 62px tall instead of the
image's real 56px because of it, which is what read as extra padding
specifically below the cover in each row. */
.play-cover {
  display: block;
  width: 56px;
  height: 56px;
  object-fit: cover;
  border-radius: var(--radius-sm);
  flex-shrink: 0;
}

.play-cover-fallback {
  object-fit: contain;
  padding: var(--space-1);
  opacity: 0.4;
  background: var(--color-surface-hover);
}

.play-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.play-name {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Text, not a second cover image next to the expansion's own one (asked
for directly, weighed against duplicating an image in a list that can
run to thousands of rows) - same "Expansión de {name}" wording the
collection/picker grids already use for the same relationship, just as
a plain muted line here instead of a colored badge, to match this
list's own denser row style. */
.play-base-game {
  color: var(--color-text-muted);
  font-size: 0.75rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.play-meta {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.load-more {
  display: block;
  margin: 0 auto;
}
</style>
