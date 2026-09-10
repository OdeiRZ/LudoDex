<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFriendDetailStore } from '@/stores/friendDetail'
import { useCollectionScrubber, normalizeLetter } from '@/composables/useCollectionScrubber'
import { FALLBACK_ICON_URL } from '@/lib/assets'
import type { Game } from '@/stores/games'
import UserAvatar from '@/components/UserAvatar.vue'
import GameCard from '@/components/GameCard.vue'
import GameDetailModal from '@/components/GameDetailModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const props = defineProps<{ friendId: number }>()
const friendDetail = useFriendDetailStore()
const { t } = useI18n()

const activeTab = ref<'collection' | 'plays'>('collection')

onMounted(() => {
  friendDetail.fetchCollection(props.friendId)
})

// Covers navigating from one friend's detail page to another's without
// this component unmounting (the route only changes :friendId) - reset
// to the collection tab and reload for the new friend, same as a fresh
// visit would.
watch(
  () => props.friendId,
  (friendId) => {
    activeTab.value = 'collection'
    friendDetail.fetchCollection(friendId)
  },
)

// Plays/stats are fetched lazily, the first time this tab is opened -
// playsLoaded is the guard against re-fetching every time it's switched
// back to.
watch(activeTab, (tab) => {
  if (tab === 'plays' && !friendDetail.playsLoaded) {
    friendDetail.fetchPlays(props.friendId, 1)
    friendDetail.fetchPlaysStats(props.friendId)
  }
})

function retryPlays() {
  friendDetail.fetchPlays(props.friendId, 1)
  friendDetail.fetchPlaysStats(props.friendId)
}

const friendName = computed(() => friendDetail.friend?.name ?? '')

// Driven by data rather than three near-identical template blocks - the
// only real difference between the three sections is which array/i18n
// strings feed them, so a single v-for renders all three.
// Alphabetical, same localeCompare DashboardView already sorts its own
// collection by - without this, the three lists render in whatever
// order the API happened to return them (found live: the real bug
// behind "the scrubber jumped to the wrong game" reports - the scrubber
// itself was never broken, it always assumes the list it's jumping
// around IS alphabetically sorted, the same way a phone book's own
// index only works because the phone book itself is). [...array] copies
// before sorting - .sort() mutates in place, and these are the store's
// own arrays, not a local copy.
function byName(games: Game[]): Game[] {
  return [...games].sort((a, b) => a.name.localeCompare(b.name))
}

const collectionSections = computed(() => [
  {
    key: 'shared',
    title: t('friends.detail.collection.shared'),
    games: byName(friendDetail.shared),
    empty: t('friends.detail.collection.emptyShared'),
  },
  {
    key: 'mineOnly',
    title: t('friends.detail.collection.mineOnly'),
    games: byName(friendDetail.mineOnly),
    empty: t('friends.detail.collection.emptyMineOnly', { name: friendName.value }),
  },
  {
    key: 'theirsOnly',
    title: t('friends.detail.collection.theirsOnly', { name: friendName.value }),
    games: byName(friendDetail.theirsOnly),
    empty: t('friends.detail.collection.emptyTheirsOnly', { name: friendName.value }),
  },
])

const detailGame = ref<Game | null>(null)

// Expanded by default (unlike PlaysView's own top-played breakdown,
// collapsed by default there - these three sections ARE this tab's main
// content, not a secondary detail to drill into) - tracks which are
// collapsed instead, so an empty set means "everything open" without
// needing to seed it with all three keys up front. v-show (not v-if) on
// the actual game list below keeps each section's own data-letter
// elements in the DOM even while collapsed, so the A-Z scrubber can
// still find and query them - only their visibility toggles, not
// whether useCollectionScrubber can see them.
const collapsedSections = ref(new Set<string>())

function toggleSection(key: string) {
  if (collapsedSections.value.has(key)) {
    collapsedSections.value.delete(key)
  } else {
    collapsedSections.value.add(key)
  }

  collapsedSections.value = new Set(collapsedSections.value)
}

// GameDetailModal's own translation result only patches games.collection
// (see its own docblock) - shared/mineOnly/theirsOnly aren't part of that
// store, so without this a translation here would show in the modal but
// revert to English the next time it's reopened. Mutates the same object
// reference the v-for already holds, same pattern as PlaysView's own
// onDetailGameTranslated - the list picks it up too, not just the modal.
function onDetailGameTranslated(descriptionEs: string | null) {
  if (detailGame.value) {
    detailGame.value.description_es = descriptionEs
  }
}

const gamesListRef = ref<HTMLElement | null>(null)

// useCollectionScrubber reads entry.game.* and is typed for UserGame[] -
// these three lists are plain Game[], so each entry is wrapped to match
// that shape rather than changing the composable itself for one caller
// with a different one. id/status are never read by the composable
// itself (confirmed reading its source), only game.* is - id just
// reuses the game's own (unique here, since a game can only appear in
// one of the three sections) and status is always 'owned', matching
// what the comparison itself already only ever includes.
//
// Collapsed sections are excluded here (asked for directly) - their
// letters never show as available, so the scrubber can never offer a
// jump into a section with nothing currently visible to jump to.
// Recomputes only when collapsedSections itself changes (a deliberate
// click), never on scroll - an earlier version kept re-deriving this
// from wherever the page happened to be scrolled to, which fought with
// jumpToBucket's own scrollIntoView mid-animation (each animation frame
// fired a real scroll event, recalculating - and sometimes changing -
// the pool while the scroll it had itself just triggered was still in
// flight) and could make the whole scrubber vanish right after a
// perfectly valid click.
const scrubberPool = computed(() =>
  collectionSections.value
    .filter((section) => !collapsedSections.value.has(section.key))
    .flatMap((section) => section.games)
    .map((game) => ({ id: game.id, status: 'owned' as const, game })),
)

// Fixed to name mode - this view has no sort/order controls of its own
// (nothing asked for), unlike Dashboard's own switchable criterion.
const sortCriterion = ref<'name'>('name')
const sortOrder = ref<'asc'>('asc')

// Overrides the scrubber's own default "first match in DOM" only enough
// to skip collapsed sections - picks the first VISIBLE candidate in DOM
// order, not Dashboard/Picker's plain first-in-DOM (see the option's own
// doc comment), and deliberately not "nearest to the current scroll
// position" either, despite that sounding more helpful at first: tried
// live, and it broke the one guarantee an A-Z index actually promises -
// pressing the same letter twice from two different scroll positions
// landed on two different cards, since "nearest" tracks wherever you
// already are, not the letter itself (found live: scrubbing from Y back
// up to A landed on "Azul Stained Glass of Sintra", of all things,
// nearer to Y's own position, instead of the real first A). First in DOM
// is what every other list in this file (Dashboard, Picker) already
// promises implicitly, and what this now matches too.
//
// candidates comes from querying the whole gamesListRef, which still
// contains every section's own cards regardless of collapse state
// (v-show only hides them, it doesn't remove them) - a letter only
// being "available" when an expanded section has a match (scrubberPool
// above) does NOT mean every DOM match for it is itself visible, so a
// collapsed section's own hidden cards have to be filtered out here
// too, not just assumed away.
function resolveJumpTarget(candidates: HTMLElement[]): HTMLElement | null {
  return (
    candidates.find((el) => {
      const key = el.closest('.collection-section')?.getAttribute('data-section-key')
      return key !== null && key !== undefined && !collapsedSections.value.has(key)
    }) ?? null
  )
}

const {
  showScrubber,
  displayBuckets,
  availableBuckets,
  scrubbing,
  scrubLetter,
  scrubBubbleTop,
  scrubBubbleRight,
  hovering,
  scrubberAriaLabel,
  scrubberRef,
  scrubberStyle,
  draggingHandle,
  onScrubberEnter,
  onScrubberLeave,
  startScrub,
  moveScrub,
  endScrub,
  startHandleDrag,
  moveHandleDrag,
  endHandleDrag,
  resetHandlePosition,
} = useCollectionScrubber({
  sortCriterion,
  sortOrder,
  pool: scrubberPool,
  filtered: scrubberPool, // no hay búsqueda/filtro propio en esta vista
  listRef: gamesListRef,
  hidden: computed(() => detailGame.value !== null),
  labels: computed(() => ({
    name: t('dashboard.azScrubberLabel'),
    year: t('dashboard.yearScrubberLabel'),
    rank: t('dashboard.rankScrubberLabel'),
  })),
  resolveJumpTarget,
})

const searchInput = ref('')
let searchDebounce: ReturnType<typeof setTimeout> | undefined

function onSearchInput() {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(
    () => friendDetail.setPlaysSearch(props.friendId, searchInput.value),
    300,
  )
}

onUnmounted(() => clearTimeout(searchDebounce))

function loadMore() {
  friendDetail.fetchPlays(props.friendId, friendDetail.playsCurrentPage + 1)
}
</script>

<template>
  <div class="friend-detail">
    <div v-if="friendDetail.notFound" class="card not-found">
      <p>{{ $t('friends.detail.notFound') }}</p>
    </div>

    <template v-else>
      <div v-if="friendDetail.friend" class="friend-header">
        <div class="friend-identity">
          <UserAvatar
            :name="friendDetail.friend.name"
            :avatar-url="friendDetail.friend.avatar_url"
            :size="48"
          />
          <h1>{{ friendDetail.friend.name }}</h1>
        </div>
        <RouterLink :to="{ name: 'friends' }" class="back-link">
          {{ $t('friends.detail.backToList') }}
        </RouterLink>
      </div>

      <div class="tabs" role="tablist">
        <button
          type="button"
          role="tab"
          :aria-selected="activeTab === 'collection'"
          :class="['tab', { active: activeTab === 'collection' }]"
          @click="activeTab = 'collection'"
        >
          {{ $t('friends.detail.tabs.collection') }}
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="activeTab === 'plays'"
          :class="['tab', { active: activeTab === 'plays' }]"
          @click="activeTab = 'plays'"
        >
          {{ $t('friends.detail.tabs.plays') }}
        </button>
      </div>

      <template v-if="activeTab === 'collection'">
        <p v-if="friendDetail.activityHidden" class="empty-state">
          {{ $t('friends.detail.activityNotShared') }}
        </p>

        <div v-else-if="friendDetail.collectionError" class="load-error">
          <p role="alert" class="alert alert-error">{{ $t('common.loadError') }}</p>
          <button type="button" class="btn" @click="friendDetail.fetchCollection(props.friendId)">
            {{ $t('common.retry') }}
          </button>
        </div>

        <p v-else-if="!friendDetail.collectionLoaded" class="loading-state">
          <LoadingSpinner :size="36" />
          {{ $t('friends.detail.collection.loading') }}
        </p>

        <div v-else ref="gamesListRef">
          <section
            v-for="section in collectionSections"
            :key="section.key"
            class="collection-section"
            :data-section-key="section.key"
          >
            <button
              type="button"
              class="collection-section-header"
              :aria-expanded="!collapsedSections.has(section.key)"
              @click="toggleSection(section.key)"
            >
              <h2>{{ section.title }}</h2>
              <svg
                class="collection-section-chevron"
                :class="{ 'collection-section-chevron-collapsed': collapsedSections.has(section.key) }"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
              >
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
              </svg>
            </button>
            <p
              v-if="section.games.length === 0"
              v-show="!collapsedSections.has(section.key)"
              class="section-empty-state"
            >
              {{ section.empty }}
            </p>
            <ul v-else v-show="!collapsedSections.has(section.key)" class="games">
              <li
                v-for="game in section.games"
                :key="game.id"
                class="game-card"
                :data-letter="normalizeLetter(game.name)"
              >
                <GameCard :image-url="game.image_url" :is-expansion="game.base_game_id !== null">
                  <div class="game-card-header">
                    <h3>{{ game.name }}</h3>
                    <button
                      type="button"
                      class="details-icon-button"
                      :aria-label="$t('picker.viewDetails')"
                      :title="$t('picker.viewDetails')"
                      @click="detailGame = game"
                    >
                      <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        aria-hidden="true"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"
                        />
                        <circle
                          cx="12"
                          cy="12"
                          r="3"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                      </svg>
                    </button>
                  </div>
                  <div v-if="game.base_game_id !== null || game.bgg_id !== null" class="badge-row">
                    <span
                      v-if="game.base_game_id !== null"
                      class="badge badge-expansion"
                      :title="$t('dashboard.expansionOf', { name: game.base_game_name })"
                    >
                      {{ $t('dashboard.expansionOf', { name: game.base_game_name }) }}
                    </span>
                    <span v-else class="badge badge-rank">
                      {{
                        game.bgg_rank !== null
                          ? $t('dashboard.rank', { rank: game.bgg_rank })
                          : $t('dashboard.unranked')
                      }}
                    </span>
                  </div>
                  <p
                    v-if="game.year_published || game.min_players || game.max_players"
                    class="meta"
                  >
                    <span v-if="game.year_published">{{ game.year_published }}</span>
                    <span v-if="game.min_players || game.max_players">
                      {{
                        $t('dashboard.players', { min: game.min_players, max: game.max_players })
                      }}
                    </span>
                  </p>
                </GameCard>
              </li>
            </ul>
          </section>
        </div>
      </template>

      <template v-else>
        <p v-if="friendDetail.activityHidden" class="empty-state">
          {{ $t('friends.detail.activityNotShared') }}
        </p>

        <div v-else-if="friendDetail.playsError" class="load-error">
          <p role="alert" class="alert alert-error">{{ $t('common.loadError') }}</p>
          <button type="button" class="btn" @click="retryPlays">
            {{ $t('common.retry') }}
          </button>
        </div>

        <template v-else>
        <div
          v-if="friendDetail.playsStats && friendDetail.playsStats.total_plays > 0"
          class="stats-bar"
        >
          <div class="stat-tile">
            <span class="stat-value">{{ friendDetail.playsStats.total_plays }}</span>
            <span class="stat-label">{{ $t('plays.statsTotalPlays') }}</span>
          </div>
          <div class="stat-tile">
            <span class="stat-value">{{ friendDetail.playsStats.distinct_games }}</span>
            <span class="stat-label">{{ $t('plays.statsDistinctGames') }}</span>
          </div>
        </div>

        <div
          v-if="
            friendDetail.playsLoaded && (friendDetail.plays.length > 0 || friendDetail.playsSearch)
          "
          class="search-field"
        >
          <label for="friend-plays-search">{{ $t('plays.searchLabel') }}</label>
          <input
            id="friend-plays-search"
            v-model="searchInput"
            type="search"
            :placeholder="$t('plays.searchPlaceholder')"
            @input="onSearchInput"
          />
        </div>

        <p v-if="!friendDetail.playsLoaded" class="loading-state">
          <LoadingSpinner :size="36" />
          {{ $t('friends.detail.plays.loading') }}
        </p>

        <p
          v-else-if="friendDetail.plays.length === 0 && !friendDetail.playsSearch"
          class="empty-state"
        >
          {{ $t('friends.detail.plays.empty') }}
        </p>

        <p v-else-if="friendDetail.plays.length === 0" class="empty-state">
          {{ $t('plays.noMatches') }}
        </p>

        <ul v-else class="play-list">
          <li v-for="(play, index) in friendDetail.plays" :key="play.id" class="play-row">
            <span class="play-index">{{ index + 1 }}</span>
            <img v-if="play.game.image_url" :src="play.game.image_url" alt="" class="play-cover" />
            <img v-else :src="FALLBACK_ICON_URL" alt="" class="play-cover play-cover-fallback" />
            <div class="play-info">
              <span class="play-name">{{ play.game.name }}</span>
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
          v-if="
            friendDetail.playsLoaded && friendDetail.playsCurrentPage < friendDetail.playsLastPage
          "
          type="button"
          class="btn load-more"
          :disabled="friendDetail.playsLoading"
          @click="loadMore"
        >
          {{ $t('plays.loadMore') }}
        </button>
        </template>
      </template>
    </template>

    <GameDetailModal
      v-if="detailGame"
      :game="detailGame"
      @close="detailGame = null"
      @translated="onDetailGameTranslated"
    />

    <div
      v-if="showScrubber"
      ref="scrubberRef"
      class="az-scrubber"
      :class="{ 'az-scrubber-visible': hovering || scrubbing || draggingHandle }"
      role="navigation"
      :aria-label="scrubberAriaLabel"
      :style="scrubberStyle"
      @pointerenter="onScrubberEnter"
      @pointerleave="onScrubberLeave"
    >
      <div
        class="az-scrubber-handle"
        :aria-label="$t('dashboard.scrubberMoveLabel')"
        :title="$t('dashboard.scrubberMoveLabel')"
        @pointerdown="startHandleDrag"
        @pointermove="moveHandleDrag"
        @pointerup="endHandleDrag"
        @pointercancel="endHandleDrag"
        @dblclick="resetHandlePosition"
      >
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <circle cx="8" cy="8" r="1.5" />
          <circle cx="16" cy="8" r="1.5" />
          <circle cx="8" cy="16" r="1.5" />
          <circle cx="16" cy="16" r="1.5" />
        </svg>
      </div>
      <div
        class="az-scrubber-buckets"
        @pointerdown="startScrub"
        @pointermove="moveScrub"
        @pointerup="endScrub"
        @pointercancel="endScrub"
      >
        <span
          v-for="bucket in displayBuckets"
          :key="bucket"
          class="az-scrubber-letter"
          :class="{ 'az-scrubber-letter-available': availableBuckets.has(bucket) }"
        >
          {{ bucket }}
        </span>
      </div>
    </div>

    <div
      v-if="scrubbing && scrubLetter"
      class="az-scrubber-bubble"
      :style="{
        top: `${scrubBubbleTop}px`,
        right: `${scrubBubbleRight}px`,
        transform: 'translateY(-50%)',
      }"
    >
      {{ scrubLetter }}
    </div>
  </div>
</template>

<style scoped>
.friend-detail {
  max-width: 640px;
  margin: 0 auto;
}

.back-link {
  display: inline-block;
  color: var(--color-text-muted);
  font-size: 0.9rem;
  white-space: nowrap;
}

/* justify-content: space-between puts .back-link flush against the
block's own right edge, at the same height as the name next to it,
instead of stacked above on its own line. */
.friend-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  margin-bottom: var(--space-4);
}

.friend-identity {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  min-width: 0;
}

.friend-header h1 {
  margin: 0;
  overflow-wrap: anywhere;
}

.not-found {
  color: var(--color-text-muted);
}

.tabs {
  display: flex;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
  border-bottom: 1px solid var(--color-border-strong);
}

.tab {
  padding: var(--space-2) var(--space-3);
  border: none;
  background: none;
  color: var(--color-text-muted);
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
}

.tab.active {
  color: var(--color-heading);
  border-bottom-color: var(--color-primary);
  font-weight: 500;
}

/* .loading-state/.empty-state themselves come from the global main.css
now (see the audit note below) - only the genuinely different case gets
its own class here. */

.collection-section {
  margin-bottom: var(--space-6);
}

.collection-section h2 {
  font-size: 1rem;
  margin: 0;
}

/* Button reset (border/background/font/text-align) so the clickable
header still reads as a plain title row, same technique already used
for PlaysView's own expandable top-played rows - width: 100% plus
justify-content: space-between is what pushes the chevron to the row's
own right edge instead of sitting flush against the title. */
.collection-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  width: 100%;
  margin-bottom: var(--space-3);
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  color: inherit;
  text-align: left;
  cursor: pointer;
}

.collection-section-chevron {
  flex-shrink: 0;
  width: 1.1rem;
  height: 1.1rem;
  color: var(--color-text-muted);
  transition: transform 0.15s ease;
}

.collection-section-chevron-collapsed {
  transform: rotate(-90deg);
}

/* Deliberately NOT .empty-state: that one is the single, page-level
"nothing here at all" message (activityHidden/no plays,
all elsewhere in this file) - the global version's big padding and
centered text fit a lone message taking over the whole tab. This is a
compact note repeated up to three times in a row, one per collection
section (found via a CSS-consistency audit - this used to redefine
.empty-state itself, silently diverging from what the same class name
means everywhere else in the app). */
.section-empty-state {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.games {
  list-style: none;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: var(--space-3);
}

.games :deep(.game-card-header) {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.games :deep(h3) {
  font-size: 0.95rem;
  overflow-wrap: anywhere;
  flex: 1;
  min-width: 0;
}

/* Same size/style/z-index fix as Dashboard's own details button - see
its own comment there for why z-index: 21 specifically: without it,
.az-scrubber (scrubber.css, position: fixed; z-index: 20) wins the
overlap with this button on a narrow phone regardless of DOM order. */
.games :deep(.details-icon-button) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  flex-shrink: 0;
  margin-right: var(--space-1);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text-muted);
  position: relative;
  z-index: 21;
}

.games :deep(.details-icon-button:hover) {
  background: var(--color-surface-hover);
  color: var(--color-text);
}

.games :deep(.details-icon-button svg) {
  width: 16px;
  height: 16px;
}

.games :deep(.badge-row) {
  display: flex;
  align-self: flex-start;
  max-width: 100%;
  gap: var(--space-2);
  flex-wrap: wrap;
}

.games :deep(.badge-rank) {
  background: rgba(255, 255, 255, 0.2);
  color: #fff;
}

/* Same violet as the card's own left border (.game-cover.expansion), so
the badge reads as the text version of that same marker instead of an
unrelated color. Same pattern as Dashboard/Picker's own .badge-expansion. */
.games :deep(.badge-expansion) {
  background: var(--color-expansion);
  color: #fff;
}

/* "Expansión de <nombre>" carries the base game's own name, which can run
long enough to push past the card's edge - without this the card clips it
abruptly mid-word instead of showing it's truncated. min-width: 0 lets the
flex item actually shrink; text-overflow needs the display override since
the base .badge is inline-flex. */
.games :deep(.badge-expansion) {
  display: inline-block;
  max-width: 100%;
  min-width: 0;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.games :deep(.meta) {
  display: flex;
  gap: var(--space-2);
  flex-wrap: wrap;
  font-size: 0.78rem;
  color: rgba(255, 255, 255, 0.75);
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

.search-field {
  margin-bottom: var(--space-4);
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

.play-index {
  width: 0.75rem;
  flex-shrink: 0;
  text-align: left;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

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

.play-meta {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.load-more {
  display: block;
  margin: 0 auto;
}
</style>
