<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGamesStore, type UserGame } from '@/stores/games'
import { useCollectionDensity } from '@/composables/useCollectionDensity'
import { useExpansionCounts } from '@/composables/useExpansionCounts'
import { getLocale } from '@/i18n'
import { translateCategory } from '@/i18n/bggCategories'
import DensityToggle from '@/components/DensityToggle.vue'
import GameCard from '@/components/GameCard.vue'
import GameDetailModal from '@/components/GameDetailModal.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const games = useGamesStore()
const { t } = useI18n()

// Which card's details modal (image/description) is open, if any - only
// one at a time, so a single ref rather than per-card state is enough.
const detailEntry = ref<UserGame | null>(null)
const { density, toggle: toggleDensity } = useCollectionDensity()
const locale = computed(() => getLocale())
const expansionCounts = useExpansionCounts(computed(() => games.collection))

// Starts at 2 rather than empty: the placeholder text has no room to
// display fully next to the "Solo" button at this width, and most groups
// browsing this page are more than one person anyway.
const DEFAULT_PLAYERS = 2
const players = ref<number | null>(DEFAULT_PLAYERS)
// Buckets rather than a free-form number: nobody thinks "I have exactly 47
// minutes", they think "about an hour" - and it doubles as a mobile-friendly
// tap target instead of opening a number keyboard for one value.
type DurationBucket = 'any' | '30' | '60' | '90' | '120'
const durationBucket = ref<DurationBucket>('any')
// Exclusive on purpose, unlike the underlying data: a game's own
// is_cooperative/is_competitive flags are independent (a semi-cooperative
// game can be both), but as a filter "show me either" is a more useful
// question than "match both flags at once" when browsing.
const modeFilter = ref<'any' | 'cooperative' | 'competitive'>('any')
// Only a positive "has a campaign" filter, not its opposite: excluding
// campaign games specifically is a much rarer need than finding them, so a
// three-way any/campaign/arcade radio was one option too many.
const onlyCampaign = ref(false)
const categoryFilter = ref('')
const search = ref('')

// Trying this collapsed - starts expanded (same as always), a manual
// toggle only for now, not persisted - see whether it's worth keeping
// before wiring it up properly (localStorage, etc.) like density already
// is.
const filtersCollapsed = ref(false)

// Same criterion/order pair as the collection's own sort controls (and the
// same reasoning for a toggle button over a second radio group) - the
// collection can come back from the API in an order that has nothing to do
// with the name (BGG import order, insertion order...), so without this the
// results here could just as easily land reverse-alphabetical as not.
type SortCriterion = 'name' | 'rank' | 'year'

const sortCriterion = ref<SortCriterion>('name')
const sortOrder = ref<'asc' | 'desc'>('asc')

function toggleSort() {
  sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
}

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

onMounted(() => {
  if (!games.loaded) {
    games.fetchAll()
  }
})

// With a single player there's no one to cooperate or compete with, so the
// mode filters stop being meaningful - hide them and drop whatever was
// selected instead of leaving a filter silently active behind a hidden
// checkbox.
const isSoloPlayer = computed(() => players.value === 1)

// Shortcut for the common "just me" case: same effect as typing 1 into the
// Jugadores field (isSoloPlayer above reacts to either), just one tap
// instead of opening a number keyboard on mobile.
function toggleSolo() {
  players.value = isSoloPlayer.value ? DEFAULT_PLAYERS : 1
}

watch(isSoloPlayer, (solo) => {
  if (solo) {
    modeFilter.value = 'any'
  }
})

// Only games you actually own, and never a standalone expansion (it isn't
// playable without its base game - see the README's expansions note).
const playable = computed(() =>
  games.collection.filter((entry) => entry.status === 'owned' && entry.game.base_game_id === null),
)

// An owned expansion can extend the player count or add a campaign mode
// the base game doesn't have on its own - Root's "Ribereños"/
// "Subterráneos" expansions go from 4 to 6 players, Spirit Island's
// "Nature Incarnate" adds a campaign mode, and so on. BGG reports these on
// the expansion's own listing as the new combined range, not as a delta
// on top of the base game's, so this takes the wider of the two instead of
// just the base game's own printed numbers. Only expansions actually
// owned (not wishlisted) count, matching the same "your real shelf" rule
// the rest of this page already applies - and only fills in whichever
// value the base game itself has, so a game not in this collection at all
// (an expansion's own base_game_id pointing outside it) is never touched.
const effectiveStatsByGameId = computed(() => {
  const stats: Record<string, { minPlayers: number | null; maxPlayers: number | null; hasCampaign: boolean }> = {}

  for (const { game } of playable.value) {
    stats[game.id] = { minPlayers: game.min_players, maxPlayers: game.max_players, hasCampaign: game.has_campaign }
  }

  for (const entry of games.collection) {
    const baseId = entry.game.base_game_id
    const current = baseId !== null ? stats[baseId] : undefined

    if (entry.status !== 'owned' || !current) {
      continue
    }

    const expansion = entry.game

    if (expansion.min_players !== null && (current.minPlayers === null || expansion.min_players < current.minPlayers)) {
      current.minPlayers = expansion.min_players
    }

    if (expansion.max_players !== null && (current.maxPlayers === null || expansion.max_players > current.maxPlayers)) {
      current.maxPlayers = expansion.max_players
    }

    if (expansion.has_campaign) {
      current.hasCampaign = true
    }
  }

  return stats
})

// Built from the playable collection itself, not the full catalog
// (games.categoryOptions): a category only some wishlist/unowned game has
// would otherwise show up as a choice that can never return a result here.
const availableCategories = computed(() =>
  [...new Set(playable.value.flatMap(({ game }) => game.categories))].sort((a, b) =>
    translateCategory(a, locale.value).localeCompare(translateCategory(b, locale.value)),
  ),
)

// v-model.number leaves the ref as an empty string (not null) when the
// input is cleared - "" < 3 coerces to 0 < 3 in JS, so without this guard
// clearing the field would silently filter out every game with any minimum
// player count instead of removing the filter.
function asFilterNumber(value: number | null): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

// What shows in place of the full filter form while it's collapsed - only
// the parts that actually narrow the results, so a still-default duration/
// mode/category doesn't clutter it with "Cualquiera" three times over.
const filterSummary = computed(() => {
  const parts: string[] = []

  if (search.value.trim() !== '') {
    parts.push(`"${search.value.trim()}"`)
  }

  parts.push(isSoloPlayer.value ? t('picker.solo') : t('picker.playersCount', { count: players.value }))

  if (durationBucket.value !== 'any') {
    const durationLabel =
      durationBucket.value === '30'
        ? t('picker.upTo30')
        : durationBucket.value === '60'
          ? t('picker.upTo1h')
          : durationBucket.value === '90'
            ? t('picker.upTo1h30')
            : t('picker.upTo2h')
    parts.push(durationLabel)
  }

  if (!isSoloPlayer.value && modeFilter.value !== 'any') {
    parts.push(modeFilter.value === 'cooperative' ? t('picker.cooperative') : t('picker.competitive'))
  }

  if (onlyCampaign.value) {
    parts.push(t('picker.onlyCampaign'))
  }

  if (categoryFilter.value !== '') {
    parts.push(translateCategory(categoryFilter.value, locale.value))
  }

  return parts.join(' · ')
})

const filtered = computed(() => {
  const minPlayersFilter = asFilterNumber(players.value)
  const maxDurationFilter = durationBucket.value === 'any' ? null : Number(durationBucket.value)
  const query = search.value.trim().toLowerCase()

  const base = playable.value.filter(({ game }) => {
    if (query !== '' && !game.name.toLowerCase().includes(query)) return false

    const stats = effectiveStatsByGameId.value[game.id]

    if (minPlayersFilter !== null) {
      if (stats.minPlayers !== null && minPlayersFilter < stats.minPlayers) return false
      if (stats.maxPlayers !== null && minPlayersFilter > stats.maxPlayers) return false
    }

    if (maxDurationFilter !== null) {
      // Many games (including some imported straight from BGG) only ever
      // get one playtime value filled in, not a real min/max pair - using
      // max_playtime_minutes alone meant this filter silently excluded
      // nothing whenever it was blank, which was most of the collection.
      // Falling back to whichever value exists uses the game's shortest
      // known commitment as the bar to clear.
      const shortestKnownPlaytime = game.min_playtime_minutes ?? game.max_playtime_minutes
      if (shortestKnownPlaytime !== null && shortestKnownPlaytime > maxDurationFilter) {
        return false
      }
    }

    if (!isSoloPlayer.value) {
      if (modeFilter.value === 'cooperative' && !game.is_cooperative) return false
      if (modeFilter.value === 'competitive' && !game.is_competitive) return false
    }

    if (onlyCampaign.value && !stats.hasCampaign) return false

    if (categoryFilter.value !== '' && !game.categories.includes(categoryFilter.value)) {
      return false
    }

    return true
  })

  if (sortCriterion.value === 'rank') {
    return base.sort((a, b) => {
      const rankA = a.game.bgg_rank
      const rankB = b.game.bgg_rank

      // Same as the collection's own rank sort: unranked games (never
      // linked to BGG, or too few votes there to place) have no meaningful
      // position, so they always sink to the bottom regardless of order.
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
      // publication year has no meaningful position on a timeline, so it
      // always sinks to the bottom regardless of direction.
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
</script>

<template>
  <div>
    <div class="title-row" :class="{ 'filters-collapsed': filtersCollapsed }">
      <h1>{{ $t('picker.title') }}</h1>
      <span v-if="games.loaded && playable.length" class="count">{{
        $t('common.gamesCount', { count: filtered.length })
      }}</span>
      <div v-if="games.loaded && playable.length" class="title-density-toggle">
        <DensityToggle :density="density" @toggle="toggleDensity" />
      </div>
    </div>

    <div v-if="games.loaded && playable.length && filtersCollapsed" class="filters-summary card">
      <p>{{ filterSummary }}</p>
      <button
        type="button"
        class="btn filters-toggle filters-toggle-floating"
        :aria-expanded="false"
        :aria-label="$t('picker.showFilters')"
        :title="$t('picker.showFilters')"
        @click="filtersCollapsed = false"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <line x1="21" x2="14" y1="4" y2="4" />
          <line x1="10" x2="3" y1="4" y2="4" />
          <line x1="21" x2="12" y1="12" y2="12" />
          <line x1="8" x2="3" y1="12" y2="12" />
          <line x1="21" x2="16" y1="20" y2="20" />
          <line x1="12" x2="3" y1="20" y2="20" />
          <line x1="14" x2="14" y1="2" y2="6" />
          <line x1="8" x2="8" y1="10" y2="14" />
          <line x1="16" x2="16" y1="18" y2="22" />
        </svg>
        <svg class="filters-toggle-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
        </svg>
      </button>
    </div>

    <form v-if="games.loaded && playable.length && !filtersCollapsed" class="filters card" @submit.prevent>
      <button
        type="button"
        class="btn filters-toggle filters-toggle-open filters-toggle-floating"
        aria-expanded="true"
        :aria-label="$t('picker.hideFilters')"
        :title="$t('picker.hideFilters')"
        @click="filtersCollapsed = true"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <line x1="21" x2="14" y1="4" y2="4" />
          <line x1="10" x2="3" y1="4" y2="4" />
          <line x1="21" x2="12" y1="12" y2="12" />
          <line x1="8" x2="3" y1="12" y2="12" />
          <line x1="21" x2="16" y1="20" y2="20" />
          <line x1="12" x2="3" y1="20" y2="20" />
          <line x1="14" x2="14" y1="2" y2="6" />
          <line x1="8" x2="8" y1="10" y2="14" />
          <line x1="16" x2="16" y1="18" y2="22" />
        </svg>
        <svg class="filters-toggle-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
        </svg>
      </button>

      <div class="search-field">
        <label for="search">{{ $t('picker.searchLabel') }}</label>
        <input
          id="search"
          v-model="search"
          type="search"
          :placeholder="$t('picker.searchPlaceholder')"
        />
      </div>

      <div>
        <label for="players">{{ $t('picker.players') }}</label>
        <div class="players-row">
          <input
            id="players"
            v-model.number="players"
            type="number"
            min="1"
            placeholder="Nº"
          />
          <button
            type="button"
            class="btn"
            :class="{ 'btn-primary': isSoloPlayer }"
            :aria-pressed="isSoloPlayer"
            @click="toggleSolo"
          >
            {{ $t('picker.solo') }}
          </button>
        </div>
      </div>

      <fieldset class="structure-field">
        <legend>{{ $t('picker.structureLegend') }}</legend>
        <label class="checkbox-label">
          <input v-model="onlyCampaign" type="checkbox" />
          {{ $t('picker.onlyCampaign') }}
        </label>
      </fieldset>

      <div v-if="availableCategories.length" class="genre-field">
        <label for="category">{{ $t('picker.genre') }}</label>
        <select id="category" v-model="categoryFilter">
          <option value="">{{ $t('picker.any') }}</option>
          <option v-for="category in availableCategories" :key="category" :value="category">
            {{ translateCategory(category, locale) }}
          </option>
        </select>
      </div>

      <div class="sort-field">
        <label for="sort-criterion">{{ $t('dashboard.sortByLabel') }}</label>
        <div class="sort-row">
          <select id="sort-criterion" v-model="sortCriterion">
            <option value="name">{{ $t('dashboard.sortByName') }}</option>
            <option value="rank">{{ $t('dashboard.sortByRank') }}</option>
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
        </div>
      </div>

      <fieldset class="duration-field">
        <legend>{{ $t('picker.duration') }}</legend>
        <label><input v-model="durationBucket" type="radio" value="any" /> {{ $t('picker.any') }}</label>
        <label><input v-model="durationBucket" type="radio" value="30" /> {{ $t('picker.upTo30') }}</label>
        <label><input v-model="durationBucket" type="radio" value="60" /> {{ $t('picker.upTo1h') }}</label>
        <label><input v-model="durationBucket" type="radio" value="90" /> {{ $t('picker.upTo1h30') }}</label>
        <label><input v-model="durationBucket" type="radio" value="120" /> {{ $t('picker.upTo2h') }}</label>
      </fieldset>

      <fieldset v-if="!isSoloPlayer" class="mode-fieldset">
        <legend>{{ $t('picker.mode') }}</legend>
        <label><input v-model="modeFilter" type="radio" value="any" /> {{ $t('picker.any') }}</label>
        <label><input v-model="modeFilter" type="radio" value="cooperative" /> {{ $t('picker.cooperative') }}</label>
        <label><input v-model="modeFilter" type="radio" value="competitive" /> {{ $t('picker.competitive') }}</label>
      </fieldset>

      <div v-if="games.loaded && playable.length" class="density-toggle-slot">
        <span class="filter-label-spacer" aria-hidden="true">&nbsp;</span>
        <DensityToggle :density="density" @toggle="toggleDensity" />
      </div>
    </form>

    <p v-if="games.loading" class="loading-state">
      <LoadingSpinner :size="28" />
      {{ $t('common.loadingCollection') }}
    </p>
    <p v-else-if="playable.length === 0" class="empty-state">
      {{ $t('picker.emptyOwned') }}<br />
      <RouterLink :to="{ name: 'add-game' }">{{ $t('picker.addOne') }}</RouterLink>.
    </p>
    <p v-else-if="filtered.length === 0" class="empty-state">
      {{ $t('picker.noMatches') }}
    </p>

    <ul v-else class="results" :class="{ compact: density === 'compact' }">
      <li v-for="entry in filtered" :key="entry.id" class="game-card">
        <GameCard :image-url="entry.game.image_url" :compact="density === 'compact'">
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
          <p
            v-if="
              entry.game.year_published ||
              effectiveStatsByGameId[entry.game.id]?.minPlayers ||
              effectiveStatsByGameId[entry.game.id]?.maxPlayers ||
              entry.game.min_playtime_minutes ||
              entry.game.max_playtime_minutes
            "
            class="meta"
          >
            <span v-if="entry.game.year_published">{{ entry.game.year_published }}</span>
            <span
              v-if="effectiveStatsByGameId[entry.game.id]?.minPlayers || effectiveStatsByGameId[entry.game.id]?.maxPlayers"
            >
              {{
                $t('dashboard.players', {
                  min: effectiveStatsByGameId[entry.game.id]?.minPlayers,
                  max: effectiveStatsByGameId[entry.game.id]?.maxPlayers,
                })
              }}
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
          <p
            v-if="
              entry.game.is_cooperative ||
              entry.game.is_competitive ||
              effectiveStatsByGameId[entry.game.id]?.hasCampaign ||
              entry.game.bgg_id !== null ||
              expansionCounts[entry.game.id]
            "
            class="tags"
          >
            <span v-if="entry.game.is_cooperative" class="badge badge-primary">{{ $t('picker.cooperative') }}</span>
            <span v-if="entry.game.is_competitive" class="badge badge-primary">{{ $t('picker.competitive') }}</span>
            <span v-if="effectiveStatsByGameId[entry.game.id]?.hasCampaign" class="badge badge-accent">{{
              $t('picker.campaign')
            }}</span>
            <span v-if="entry.game.bgg_id !== null" class="badge badge-rank">
              {{
                entry.game.bgg_rank !== null
                  ? $t('dashboard.rank', { rank: entry.game.bgg_rank })
                  : $t('dashboard.unranked')
              }}
            </span>
            <span v-if="expansionCounts[entry.game.id]" class="badge badge-expansion">
              {{ $t('dashboard.expansionsCount', { count: expansionCounts[entry.game.id] }) }}
            </span>
          </p>
        </GameCard>
      </li>
    </ul>

    <GameDetailModal v-if="detailEntry" :game="detailEntry.game" @close="detailEntry = null" />
  </div>
</template>

<style scoped>
/* margin-bottom lives here, not on h1 directly (reported directly: no
breathing room below the block once count/toggle wrap below the
title) - h1's own bottom margin only ever created space below h1
itself, which is the same as "below the whole row" while everything
fits on one line, but stops being true the moment anything wraps
under it (h1 is no longer the row's own lowest content). Living on the
row itself instead means it's always the space below whatever ends up
at the bottom, wrapped or not. */
.title-row {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
  flex-wrap: wrap;
  margin-bottom: var(--space-4);
}

.count {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

/* Lives in two spots - standalone in .filters-summary while collapsed
(the only way back in once the form itself is gone), and floating over
the filters form itself while expanded (.filters-toggle-floating below)
- same small size in both so it reads as the same control either way. */
.filters-toggle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 1px;
  width: 28px;
  height: 28px;
  padding: 0;
  flex-shrink: 0;
  border: none;
}

.filters-toggle svg {
  width: 13px;
  height: 13px;
  flex-shrink: 0;
}

.filters-toggle-chevron {
  width: 10px;
  height: 10px;
  transition: transform 0.15s ease;
}

/* Standard expand/collapse chevron convention (points down toward the
hidden content while collapsed, flips to point back up once it's
open) - the opposite of DensityToggle/ThemeToggle's "icon shows what
clicking leads to" on this same page, but this one is about revealing
a section, not switching between two equally-valid states. */
.filters-toggle-open .filters-toggle-chevron {
  transform: rotate(180deg);
}

/* Absolutely positioned instead of a flex participant, on purpose: this
card already has close to a dozen breakpoint-specific rules governing
how its fields wrap/reorder/resize, and a flex item here would need its
own rule at every single one of them to avoid colliding with whichever
field lands in the top-right corner at that width. Anchored to the card
itself (see .filters' own position: relative) and simply overlaid on
top of that corner instead - keeps every field at exactly the position/
spacing it already had rather than pushing the whole card taller to
carve out clear space for this. */
.filters-toggle-floating {
  position: absolute;
  top: 0;
  right: 0;
}

/* Always shown - the .density-toggle-slot copy inside the filters form
(unconditionally hidden further down) never renders any more, at any
width. Same "two renditions, toggle via CSS" pattern used everywhere
else on this page, just with only one rendition actually live now. */
.title-density-toggle {
  display: block;
  align-self: center;
  margin-left: auto;
  /* Nudges it a touch further left than flush-right so it sits centered
  over whatever it's floating below it, whenever this copy is flush
  against the title row's own right edge. */
  margin-right: 5px;
}

/* .title-row's own flex-wrap (no width override otherwise) drops
whichever item runs out of room first in flex order - by default that
was .count (DOM order: title, count, toggle), wrapping alone at a real
346px before the title+toggle pair themselves stopped fitting at 306px
(both reported directly). A first attempt just swapped their flex
order to reverse that priority, but order controls visual position too
- once both ended up sharing line 2, the toggle rendered first (left)
and the count after it, backwards from the collection toolbar's own
equivalent state (count left, toggle flush against the right edge,
matching image reference). Switched to the same technique that other
toolbar already uses instead of patching flex order further: a small
grid, title and count stacked in one column, the toggle in its own
column spanning both rows and bottom-aligned so it lines up with count
specifically, not centered across the title+count block's combined
height. */
@media (max-width: 346px) {
  .title-row {
    display: grid;
    grid-template-columns: 1fr auto;
    grid-template-areas:
      'title   toggle'
      'count   toggle';
    column-gap: var(--space-2);
    /* 0.5rem (asked for directly) - title and count sat flush against
    each other once stacked without this. */
    row-gap: 0.5rem;
  }

  .title-row h1 {
    grid-area: title;
  }

  .count {
    grid-area: count;
  }

  .title-density-toggle {
    grid-area: toggle;
    align-self: end;
    margin-left: 0;
    margin-right: 0;
  }
}

/* position: relative anchors .filters-toggle-floating, same as .filters
itself - the button isn't a flex participant here either, so this no
longer needs justify-content: space-between to push it to the far side. */
/* margin-bottom: 5px (asked for directly) only applies here, while
filters are collapsed - .filters itself (the expanded form) has its
own separate margin-bottom, halved rather than matched to this one. */
/* padding: 0.75rem (asked for directly) overrides the global .card
class's own var(--space-4) - a one-line filter summary doesn't need as
much breathing room as an actual content card. */
.filters-summary {
  position: relative;
  padding: 0.75rem;
  color: var(--color-text-muted);
  font-size: 0.9rem;
  margin-bottom: 5px;
}

.filters-summary p {
  margin: 0;
}

/* The toggle lives next to the title instead of embedded in the form,
at every width now - used to be three separate tiers (481-806px,
807-981px, 982-1023px) each reordering the form's fields to tuck the
toggle in wherever it happened to fit (sharing a line with Ordenar
por, or with Minutos, depending on the tier), until it was asked to
just live at the title consistently across all of them instead -
confirmed with real (not simulated) windows at 768px, 820px and 987px.
Bounded first at 481-1023px, then 481-1002px, then dropped the lower
481px bound too (the ≤480px tier's own form-embedded copy was kept as
an exception until that turned out to be unwanted as well) and finally
the upper 1002px bound (full desktop still fell back to the
form-embedded copy, sharing Minutos/Modo's row - asked to live in the
title row unconditionally instead, same as everywhere narrower). */
.filters > .density-toggle-slot {
  /* .filters > div (unconditional, no media query) sets display: flex
  on this same element with higher specificity (class + element vs.
  just a class) than a plain .density-toggle-slot selector here would
  have - matching its ".filters > " prefix is what actually outweighs
  it, same fix as everywhere else this page hides that slot. */
  display: none;
}

/* 807-1002px: picks up right where the 481-806px tier above leaves
off, up to this whole file's own upper bound (1002px, see the
density-toggle rule near the top). Used to be three separate tiers
(807-972px,
973-981px, 982-1002px) that grew apart over a few rounds of separate
edits until Género ended up on row 1 in some and row 2 in others,
visibly "jumping" between rows at the 972px/973px and 973px/982px
seams on an otherwise continuous resize - merged into one range since
there's no remaining reason for any of them to differ.

Row 1 (Buscar/Jugadores/Estructura): Género moves down to row 2 -
Buscar/Jugadores/Estructura alone were leaving this row noticeably
shorter than the others once Género's own 180px joined whichever row
had more slack to spare, so Género gets a row of its own controls to
open instead. Buscar's own always-on flex: 1 (see the shared rule with
.sort-field further down) already reaches the row's own right edge
regardless, absorbing whatever Jugadores/Estructura don't need.

A plain order on Género alone doesn't actually force it onto its own
line - flex-wrap decides which line an item lands on using its
hypothetical (flex-basis) size, before flex-grow is applied, so Buscar
still measured small enough at that stage for Género to keep fitting
alongside it regardless of Género's own order number. .filters::before
(zero-height, flex-basis: 100%) forces the actual line break instead,
landing right after Estructura (order: 1, between Estructura's own
order: 0 and Género's order: 2).

Row 2 (Género, then Minutos disponibles): order: 2 and 3 land them
here, right after the break. Género grows via flex (same technique as
Buscar above) to fill whatever Minutos' own ~528px content doesn't
need, reaching the row's right edge instead of stopping at its own
180px cap with the row short of it - Minutos itself isn't a good
candidate to be the one growing here, since its own radios stay
left-packed regardless of the fieldset's width (see the row 3 comment
below for the same reasoning applied to Modo). Checked comfortably
fits without overflowing even at this range's own narrowest width
(807px).

Row 3 (Modo, then Ordenar por): order: 4 and 5 respectively - both
wrap onto their own line naturally once Género+Minutos already fill
row 2 to more than what's left over, Modo first since its order number
is lower. Ordenar por keeps growing to the row's own right edge via
its own always-on flex: 1 (see .sort-row select's own rule further
down) same as it already did before Modo joined it here. */
@media (min-width: 807px) and (max-width: 1002px) {
  /* .filters' own row-gap: 0 (unconditional, see that rule's own
  comment) already means the break below adds no extra vertical space
  before row 2 - used to need a margin-bottom restored by hand on each
  row's own items to compensate for a nonzero row-gap catching the
  zero-height spacer line as if it were a real one, back when row-gap
  still defaulted to space-4; dropped once row-gap itself dropped to 0
  by default. */
  .filters::before {
    content: '';
    order: 1;
    flex-basis: 100%;
    height: 0;
  }

  .filters > .genre-field {
    order: 2;
    flex: 1;
    max-width: none;
  }

  .filters > .duration-field {
    order: 3;
  }

  .filters > .mode-fieldset {
    order: 4;
  }

  .filters > .sort-field {
    order: 5;
  }
}

/* 1003px+: picks up where the 807-1002px tier above leaves off. Wide
enough that Buscar/Jugadores/Estructura/Género/Ordenar por all share
row 1 without any reordering, leaving Minutos disponibles and Modo
alone on row 2 - previously joined there by the density toggle's own
form-embedded copy, which shared out whatever the row had left over;
now that the toggle lives in the title row unconditionally instead
(see the comment by .title-density-toggle), row 2 stopped well short
of its own right edge without it. flex: 1 1 auto (grow and shrink, but
from each fieldset's own natural content width rather than a shared
zero basis - flex: 1 alone would instead split the row's own full
width evenly between them regardless of how much content each
actually has, ballooning Modo's own empty space past what
justify-content below has to work with) grows both to close that gap,
and justify-content: space-between spreads each one's own radios
across the extra width instead of leaving it sit unused past them,
same technique as the ≤480px tier's own (now-removed) copy of this
rule used before it was asked to drop the padding/gap part of it. */
@media (min-width: 1003px) {
  .filters > .duration-field,
  .filters > .mode-fieldset {
    flex: 1 1 auto;
    justify-content: space-between;
  }
}

@media (max-width: 480px) {
  /* flex: 1 grows Buscar to fill whatever Jugadores' own fixed input+
  button don't need, reaching this row's right edge - Estructura no
  longer shares this row at all (see the break below), so there's
  nothing else competing for it. */
  .filters > .search-field {
    flex: 1;
    max-width: none;
  }

  /* Copies the 426px window's own natural wrap up to the rest of this
  tier (asked for directly) - Estructura used to only fall to row 2
  once it no longer fit alongside Buscar/Jugadores on row 1, which
  happened well below this tier's own 480px ceiling (Estructura's fixed
  content width left just enough slack at 480px for all three to
  squeeze onto row 1, unlike at 426px) - a visible seam between two
  different row-1 counts within what's supposed to be one consistent
  tier. Forced with the same .filters::before break technique used
  elsewhere in this file instead of leaving it to chance. */
  .filters::before {
    content: '';
    order: 1;
    flex-basis: 100%;
    height: 0;
  }

  /* order: 2 lands it right after the break, first - no width override
  needed any more (used to fix it at 160px so Género had something
  stable to grow against); sizes to its own content now, same as the
  481-560px tier's own copy of this rule. */
  .filters > .structure-field {
    order: 2;
  }

  /* flex: 1 grows Género to fill whatever Estructura's own content
  width doesn't need, same technique as Buscar's own row above. */
  .filters > .genre-field {
    order: 3;
    flex: 1;
    max-width: none;
  }

  /* Same 3-then-2 centered treatment as the 481-560px tier above,
  applied here too - asked for directly, since without it this tier
  fell back to the default fieldset gap (space-4, generous) with no
  forced wrap point, leaving Minutos noticeably looser and wrapping
  wherever content happened to allow rather than matching the tighter,
  centered layout right above this range. gap: var(--space-2) (tighter
  than the fieldset's own default space-4, same reduction the
  481-806px tier's own copy of this rule already uses) buys back the
  few pixels 3-per-row needs at this tier's narrower widths - asked for
  directly after 3-per-row broke down to 2-per-row earlier than wanted
  (a real 454px window) with the full space-4 gap still in place.
  padding-left/right: 0 (down from the fieldset's own default space-4
  each side, by way of space-2 then space-1 first) buys back further
  room each time 3-per-row kept breaking down earlier than wanted
  (454px, then 438px, then 404px, then a real 398px window) - a
  fieldset's own padding counts against its automatic minimum content
  size same as its gap does, so trimming it works the same way to buy
  back room for 3 per line; there's no smaller spacing token left below
  this to try next. */
  .filters > .duration-field {
    order: 4;
    width: 100%;
    gap: var(--space-2);
    padding-left: 0;
    padding-right: 0;
    justify-content: center;
  }

  /* min-width (see the 481-560px tier's own copy of this rule for the
  full reasoning) matters even more down here - this tier reaches all
  the way to a real 320px phone, narrow enough that 30% alone shrank
  the box well below "Hasta 30 min"'s own single-line width and
  wrapped it across 2-3 lines instead. 102px here specifically (rather
  than that other tier's 108px) - gap: var(--space-1) (down from the
  default space-2 between each label's own radio and text) trims
  "Hasta 30 min"'s own natural width from 102px to 98px, so the
  min-width floor needsn't sit as high to still clear it. Asked for
  directly, on top of the fieldset's own gap/padding trims above, to
  buy back a few more pixels of room for 3 per line at this tier's
  narrower widths (a real 422px window this time). Doesn't cover all
  the way down to 480px's own lower bound on its own any more - see
  the ≤388px tier further down, which layers a smaller font-size on
  top of this for the narrowest widths this reaches. min-width: 92px
  tracks "Hasta 1h30" (86px at this gap), the widest label now that
  "Hasta 30 min" was shortened to "Hasta 30m". */
  .filters > .duration-field label {
    flex: 0 1 30%;
    gap: var(--space-1);
    min-width: 92px;
    justify-content: center;
  }

  /* Pushed to the end via order (6, bumped up from 1 now that the
  break above and Estructura/Género/Minutos take 2, 3 and 4), past
  Minutos and Modo - moves it off its old spot above Minutos and lets
  it close out the form alone on its own row, rather than physically
  relocating the markup and disturbing wider layouts where this media
  query doesn't apply. flex: 1 grows it to fill the row, replacing a
  fixed 260px cap that was tuned to comfortably clear "Ranking BGG"
  specifically at a real 343-363px phone budget - flex: 1 tracks each
  width's own real leftover space instead of a number guessed for the
  narrowest case. .sort-row select's own always-on flex: 1 (see that
  rule's own comment, defined once for every tier) is what actually
  makes the select itself grow along with .sort-field now, same as
  everywhere else in this file - the 9rem fixed width this tier used to
  set for it is gone along with the fixed 260px cap that made it
  necessary. .density-toggle-slot doesn't need its own order override
  here: it lives in the title row at every width now (see the comment
  by .title-density-toggle), so this form-embedded copy never renders
  in the first place. */
  /* margin-top: var(--space-2) (asked for directly) opens a bit of
  space above Ordenar por, which otherwise sits flush against Modo
  right above it - .filters' own row-gap: 0 (unconditional, see that
  rule's own comment) leaves every row in this tier flush by default,
  and unlike the rows above it Ordenar por's own select/button don't
  carry enough visual weight of their own to read as a clearly
  separate row without a little help. */
  .filters > .sort-field {
    order: 6;
    flex: 1;
    max-width: none;
    margin-top: var(--space-2);
  }

  /* order: 5 lands it right after Minutos, before Ordenar por's own
  order: 6. width: 100% stretches the box. justify-content: center
  (asked for directly) centers its own 3 radios as a group within that
  now-wider box, instead of the default flex-start packing them
  against the left edge. gap: var(--space-2) and padding: var(--space-2)
  var(--space-3) trim the fieldset's own default space-4 gap/padding a
  little, to buy back enough width for the 3 radios to keep sharing one
  line down to a narrower window than the unmodified default managed
  (asked for directly, a real 412px window) - lighter than the space-1
  gap/padding this fieldset used to carry before that was asked to be
  dropped for visibly shrinking the box; this is a smaller trim than
  that one, on purpose, to keep more of the original breathing room. */
  .mode-fieldset {
    order: 5;
    width: 100%;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-3);
    justify-content: center;
  }
}

/* ≤388px: layers a smaller font-size on top of the ≤480px tier above,
asked for directly once neither Minutos disponibles' 3-then-2 layout
nor Modo's single line held any further down than a real 407px window
on gap/padding trims alone (388px was asked to be covered specifically
- everything from 389px up to 480px keeps the ≤480px tier's own
default 14px, so there's a real gap between 389px and ~406px where
neither field's layout holds; asked to leave it that way rather than
also patching that range). font-size: 12px (down from the fieldset
label's own default 14px) shrinks each label's own natural width
enough to close the remaining gap for both fields at once. */
@media (max-width: 388px) {
  /* min-width: 82px (down from the ≤480px tier's own 92px) tracks
  "Hasta 1h30" (now the widest label - "Hasta 30 min" held that title
  until it was shortened to "Hasta 30m") at 76px at this smaller font
  size (including the ≤480px tier's own space-1 gap) - left higher, it
  would cap 3-per-row's own reachable width well above what the
  smaller text now actually needs. */
  .filters > .duration-field label {
    min-width: 82px;
    font-size: 12px;
  }

  .filters > .mode-fieldset label {
    font-size: 12px;
  }

  /* Same font-size: 12px, extended to Buscar's own label and input too
  - asked for directly, for consistency with Minutos/Modo's own labels
  at this width rather than leaving Buscar alone at the default 14px. */
  .filters > .search-field label,
  .filters > .search-field input {
    font-size: 12px;
  }

  /* Extended further, asked for directly: Jugadores' own label, the
  Solo button, every remaining fieldset's own legend (Estructura,
  Minutos disponibles, Modo - Minutos/Modo's own radio labels are
  already covered above, just not their legends until now), and the
  text inside both selects (Género, Ordenar por). */
  .filters > div label[for='players'],
  .players-row .btn,
  .filters > .structure-field legend,
  .filters > .duration-field legend,
  .filters > .mode-fieldset legend,
  .filters > .genre-field select,
  .filters > .sort-field select {
    font-size: 12px;
  }

  /* Extended once more, asked for directly: Estructura's own checkbox
  label ("Modo campaña"), the sort-order toggle button (A → Z), and
  Género/Ordenar por's own labels (both were only covered above for
  their select's own text, not the label above it). */
  .filters > .structure-field .checkbox-label,
  .filters > .sort-field .sort-toggle,
  .filters > .genre-field label,
  .filters > .sort-field > label {
    font-size: 12px;
  }

  /* Trims Modo's own gap/padding further still, layered on top of the
  ≤480px tier's own space-2/(space-2 space-3) - asked for directly,
  once font-size: 12px above still wasn't enough on its own to hold 1
  line down to a real 356px window. padding: var(--space-1) on every
  side (not just left/right) closes the last few pixels once
  space-1/space-2 alone fell 4px short of it. */
  .filters > .mode-fieldset {
    gap: var(--space-1);
    padding: var(--space-1);
  }
}

/* ≤352px: layers a further trim onto Minutos disponibles' own copy of
this rule in the ≤388px tier above, asked for directly once that
tier's own font-size: 12px plus min-width: 82px still fell short of
holding the 3-then-2 layout down to a real 352px window. */
@media (max-width: 352px) {
  /* gap: 0 (down from the ≤480px tier's own space-1) trims "Hasta
  1h30" (the widest label) further, from 76px to 72px at this font
  size. min-width: 78px follows that smaller natural width down, with
  a couple of px of slack. */
  .filters > .duration-field label {
    gap: 0;
    min-width: 78px;
  }

  /* gap: var(--space-1) (down from the ≤480px tier's own space-2)
  trims the fieldset's own gap between labels further, same reasoning
  as the label's own gap trim just above. */
  .filters > .duration-field {
    gap: var(--space-1);
  }
}

.filters {
  display: flex;
  /* column-gap tighter than the space-6 this used before - freeing up a
  few pixels per gap is what lets Ordenar por fit on the same row as the
  rest of the top-line filters instead of wrapping to its own line.
  row-gap dropped to 0 (asked for directly) - the elements within a
  line already have their own visual weight from column-gap alone, and
  that same space-4 repeated vertically between lines read as excess on
  top of it. Used to be plain gap: var(--space-4) (both axes the same),
  overridden back to 0 per tier wherever a forced line break needed it
  (807-1002px, 481-560px) - dropped to 0 here instead so every tier
  gets flush rows by default, without needing a per-tier override for
  it any more. */
  column-gap: var(--space-4);
  row-gap: 0;
  flex-wrap: wrap;
  align-items: flex-start;
  /* Halved from space-6 (asked for directly) - space-3 is exactly
  half of it (12px vs 24px). */
  margin-bottom: var(--space-3);
  /* Anchors .filters-toggle-floating - positioned instead of a flex
  participant on purpose (see its own comment) so this doesn't need
  touching per breakpoint. No reserved padding for it: it overlays
  whatever corner content is already there at a given width rather than
  pushing the whole card taller/every field down to stay clear of it. */
  position: relative;
}

/* Tablet width - bounded on both ends (rather than reusing the ≤480px
block above or just adding a plain max-width) so it can't cascade
against or get cascaded over by that block's own order/width rules;
the two ranges never overlap, so which one comes first in the file
doesn't matter either way. At this width there's room for Buscar,
Jugadores, Estructura and Genero to all share the first row instead of
Genero wrapping alone. Upper bound is 806px, not 768px - 769-806px used
to fall through to the plain unbounded default, which this tier's own
160px cap also happens to suit just as well, so it's simplest to just
extend this same tier over that gap rather than add a third
near-identical block. 807px is where the next tier up takes over
instead.

Used to also reorder Ordenar por (and, before it moved to the title
row, the density toggle) past Minutos and Modo here - dropped when the
toggle moved out, then asked back for Ordenar por alone: without an
order override, its natural DOM position sits ahead of Minutos and
Modo (confirmed at a real 768px window: each of the three lands on its
own separate line at this range's narrower widths, in that order), so
order: 1 below moves it after both instead, same technique as the
807-1002px tier above though without that tier's own width cap on
Minutos - this range's narrower widths don't leave room to force
Minutos and Modo sharing a line the way that tier does. */
@media (min-width: 481px) and (max-width: 806px) {
  /* flex: 1 (same technique as Buscar/Ordenar por's own shared rule
  further down) grows Género to fill whatever room is left on its own
  row - whether that's row 1 alongside Buscar/Jugadores/Estructura at
  this tier's wider end, or its own row further down once it wraps
  alone. Replaces a fixed 160px cap that was tuned specifically to
  avoid overflowing row 1 at a real 768px window (measured with only
  ~9px of slack) - flex: 1 tracks the row's real available width
  directly instead of guessing a number for it, so it can't overflow
  the same way a too-generous fixed cap could have. */
  .filters > .genre-field {
    flex: 1;
    max-width: none;
  }

  .filters > .sort-field {
    order: 1;
  }

  /* Asked for directly, to tighten Minutos disponibles' own 5 radios
  at a real 768px tablet window - default var(--space-4) between them
  (fieldset's own global gap) read as slightly loose at this width. */
  .filters > .duration-field {
    gap: var(--space-2);
    width: 100%;
    justify-content: space-between;
  }

  /* flex: 0 0 auto (not flex: 1, which competes with Ordenar por for
  growth from a zero basis and can land Modo on roughly half the row
  instead of its own natural content width - confirmed at a real
  740px window, where that shrunk it just enough to wrap "Competitivo"
  onto its own second line) keeps Modo at its own unwrapped width
  regardless of whether it ends up sharing a row with Ordenar por or
  alone on one of its own; Ordenar por's own select already has
  min-width: 0 (see that rule's own comment) specifically so it can
  keep giving up width instead. justify-content: space-between spreads
  Modo's own 3 radios across whatever width that ends up being either
  way, instead of leaving them bunched at the left edge. */
  .filters > .mode-fieldset {
    flex: 0 0 auto;
    justify-content: space-between;
  }
}

/* 481-646px: asked for directly - Género moves off row 1 (where it
still comfortably shares with Buscar/Jugadores/Estructura above 646px)
to join Modo instead, landing in the empty space Modo alone otherwise
leaves on its own row (Modo's own flex: 0 0 auto, set in the 481-806px
tier above, means it never grows to fill that gap itself). order: 2
puts Género after Modo (still order: 0, its own DOM position is
earlier) so it lands to Modo's right rather than displacing it; order:
3 keeps Ordenar por (already order: 1 in the tier above) coming after
both instead of also trying to squeeze onto their row. */
@media (min-width: 481px) and (max-width: 646px) {
  .filters > .genre-field {
    order: 2;
  }

  /* margin-top: var(--space-2) (asked for directly, same reasoning as
  the ≤480px tier's own copy of this rule) - Ordenar por lands alone on
  its own row 4 here (Buscar/Jugadores/Estructura, Minutos, Modo+Género,
  Ordenar por), flush against Modo's row above it thanks to .filters'
  own unconditional row-gap: 0. */
  .filters > .sort-field {
    order: 3;
    margin-top: var(--space-2);
  }
}

/* 481-560px: asked for directly - Género, Minutos disponibles, Modo
and Ordenar por each get a row of their own instead of any of them
sharing one (unlike the wider 481-806px tier above, where Género
shares row 1 or Modo's row depending on width, and Minutos/Modo/
Ordenar por only end up alone because nothing else fits alongside
them, not because anything forces it). width: 100% on each is what
actually guarantees that regardless of how much room a given width
happens to leave - plain order changes alone don't force a split
(flex-wrap decides which line an item lands on using its hypothetical
size before flex-grow/an explicit width is applied - same reasoning
as the .filters::before break below, needed for the same reason to
separate row 1 from the rest). .filters' own row-gap: 0 (unconditional,
see that rule's own comment) means the break below adds no extra
vertical space of its own - used to need a margin-bottom restored by
hand on row 1's own items and each of the four solo rows below to
compensate for a nonzero row-gap, back when row-gap still defaulted to
space-4; dropped once row-gap itself dropped to 0 by default, so rows
now sit flush against each other with no vertical gap at all in this
range. */
@media (min-width: 481px) and (max-width: 560px) {
  .filters::before {
    content: '';
    order: 1;
    flex-basis: 100%;
    height: 0;
  }

  /* Estructura joins Género on row 2 instead of staying on row 1 with
  Buscar/Jugadores - order: 2 lands it right after the break, first
  (Género's own order: 3 keeps it right after). */
  .filters > .structure-field {
    order: 2;
  }

  /* flex: 1 (not width: 100%, which only made sense while Género had
  row 2 to itself) grows Género to fill whatever Estructura's fixed
  content-width doesn't need now that it shares the row with it. */
  .filters > .genre-field {
    order: 3;
    flex: 1;
    max-width: none;
  }

  /* A fixed 30% flex-basis per label (instead of the fieldset's own
  default flex-wrap packing as many as fit per line based on their own
  text width - 4 then 1, at this tier's own width) is what forces
  exactly 3 per line - 4 would need 120% of the row plus gaps, so it
  never fits. justify-content: center is what actually centers the
  trailing row of 2 as its own pair instead of leaving it flush left
  under the first two columns - a grid's fixed column tracks (tried
  first) can't do this, since each item stays pinned to its own column
  regardless of how many rows are full; flex centers whatever's
  actually on each wrapped line independently. min-width: 96px (the
  widest label, "Hasta 1h30", measures 90px on its own single line -
  "Hasta 30m" briefly held that title as "Hasta 30 min" until it was
  shortened) floors that 30% for whenever it computes narrower than
  the label's own content - without it, a narrow enough container
  (found while reusing this same rule at ≤480px) shrinks the box below
  its content's width and wraps the label's own text across 2-3 lines
  instead, which a fixed-percentage basis alone can't prevent. */
  .filters > .duration-field {
    order: 4;
    width: 100%;
    justify-content: center;
  }

  .filters > .duration-field label {
    flex: 0 1 30%;
    min-width: 96px;
    justify-content: center;
  }

  .filters > .mode-fieldset {
    order: 5;
    width: 100%;
  }

  /* margin-top: var(--space-2) (asked for directly, same reasoning as
  the ≤480px tier's own copy of this rule) - Ordenar por lands alone on
  its own row 5 here, flush against Modo's row above it thanks to
  .filters' own unconditional row-gap: 0. */
  .filters > .sort-field {
    order: 6;
    width: 100%;
    margin-top: var(--space-2);
  }
}

/* Placed after .filters itself (not inside the ≤480px block above) so
its column-gap wins the cascade tie against .filters' own unconditional
column-gap: shorthand above - same specificity either way (both just
".filters"), so source order is what decides it, and a media query
alone doesn't add any. Tighter than the row gap (0, untouched - only
column-gap is overridden here) - Buscar growing to fill its row at
this tier leaves it tight against Jugadores without it. */
@media (max-width: 480px) {
  .filters {
    column-gap: var(--space-2);
  }
}

.filters > div {
  display: flex;
  flex-direction: column;
  max-width: 180px;
}

/* Wrapped in its own @media (rather than left unconditional) so it
structurally can't fight the ≤480px tier's own max-width: 260px for
this same field on specificity/source-order grounds - the two ranges
are mutually exclusive by width, not by which one happens to come
later in the file.

flex: 1 lets .sort-field grow past the 180px every other filter field
caps out at, filling whatever's left on its own row instead of
stopping short of the card's right edge - at a real 1024px window it
was leaving ~59px unused past it. max-width: none removes the shared
180px cap for this field specifically; .sort-row select still splits
whatever width that grows to with .sort-toggle via its own flex: 1
(and min-width: 0 - see that rule's own comment for why), so the
select actually gets bigger as .sort-field does instead of leaving the
extra width to sit as blank padding around a still-fixed-size row.

.search-field gets the same treatment, for the same reason - it's
consistently the first item on row 1 across every tier from 481px up
(whichever other fields end up sharing that row with it), and a text
input has no "widest option" content of its own the way a select or
fieldset does, so nothing stops it from absorbing whatever room the
row has left. Defined once here instead of duplicated per tier, same
as .sort-field. */
@media (min-width: 481px) {
  .filters > .search-field,
  .filters > .sort-field {
    flex: 1;
    max-width: none;
  }
}

.players-row {
  display: flex;
  gap: var(--space-2);
}

.players-row input {
  min-width: 0;
  width: 4.5rem;
  flex: none;
}

.sort-row {
  display: flex;
  gap: var(--space-2);
}

/* flex: 1 grows the select to fill whatever .sort-toggle (flex-shrink:
0, its own fixed width) doesn't need, reaching the full width of
.sort-field's own column (180px, the same cap every other filter field
keeps to) at every width from 481px up, instead of a fixed rem value
that left the row short of the column's own right edge. min-width: 0
is what actually makes that possible - a <select> otherwise refuses to
shrink below its own widest option's content ("Ranking BGG", ~135px),
which together with .sort-toggle would overflow the 180px column
instead of filling it. "Ranking BGG" clipping when picked is the
trade-off, same one every narrower breakpoint here already accepts for
other controls - below 481px, .filters > .sort-field select overrides
this again with its own fixed 9rem, tuned separately for that tier. */
.sort-row select {
  flex: 1;
  width: auto;
  min-width: 0;
}

.sort-toggle {
  flex-shrink: 0;
  white-space: nowrap;
}

.filter-label-spacer {
  display: block;
  font-size: 0.875rem;
  margin-bottom: var(--space-1);
}

.players-row .btn {
  flex-shrink: 0;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  white-space: nowrap;
}

.checkbox-label input {
  width: auto;
}

.results {
  list-style: none;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: var(--space-4);
}

.results.compact {
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: var(--space-2);
}

.results :deep(.game-card-header) {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.results :deep(.game-card-header h2) {
  font-size: 1.05rem;
  overflow-wrap: anywhere;
  flex: 1;
  min-width: 0;
}

.results :deep(.details-icon-button) {
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

.results :deep(.details-icon-button:hover) {
  background: var(--color-surface-hover);
  color: var(--color-text);
}

.results :deep(.details-icon-button svg) {
  width: 16px;
  height: 16px;
}

/* Same reasoning as the collection's cards: the mode/campaign badges'
usual tinted-transparent fill assumes a solid card background, and loses
contrast over a light patch of an arbitrary photo without a solid fill. */
.results :deep(.badge-primary) {
  background: var(--color-primary);
  color: #fff;
}

.results :deep(.badge-accent) {
  background: var(--color-accent);
  color: #fff;
}

.results :deep(.badge-rank) {
  background: rgba(255, 255, 255, 0.2);
  color: #fff;
}

/* Same violet as an expansion card's own left border (.game-cover.expansion,
in the collection - this view never lists an expansion itself, only a base
game's own "+N expansiones" badge), so the badge reads as the same color
language wherever it shows up. */
.results :deep(.badge-expansion) {
  background: var(--color-expansion);
  color: #fff;
}

/* Always light text/fixed colors here too - same reasoning as the
collection's cards, the scrim sits over a photo, not the theme background. */
.results :deep(.meta) {
  display: flex;
  gap: var(--space-3);
  flex-wrap: wrap;
  font-size: 0.8rem;
  color: rgba(255, 255, 255, 0.75);
}

.results :deep(.tags) {
  display: flex;
  gap: var(--space-2);
  flex-wrap: wrap;
}
</style>
