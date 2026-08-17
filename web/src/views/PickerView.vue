<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGamesStore } from '@/stores/games'
import { useCollectionDensity } from '@/composables/useCollectionDensity'
import { useExpansionCounts } from '@/composables/useExpansionCounts'
import { getLocale } from '@/i18n'
import { translateCategory } from '@/i18n/bggCategories'
import DensityToggle from '@/components/DensityToggle.vue'
import GameCard from '@/components/GameCard.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const games = useGamesStore()
const { t } = useI18n()
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
            <RouterLink
              :to="{ name: 'edit-game', params: { id: entry.id }, query: { from: 'picker' } }"
              class="edit-icon-button"
              :aria-label="$t('picker.editGame')"
              :title="$t('picker.editGame')"
            >
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"
                />
              </svg>
            </RouterLink>
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
  </div>
</template>

<style scoped>
h1 {
  margin-bottom: var(--space-4);
}

.title-row {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
  flex-wrap: wrap;
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

/* Hidden by default - only the .density-toggle-slot copy inside the
filters form shows outside the narrow band below. Same "two renditions,
toggle via CSS" pattern used everywhere else on this page: the two spots
sit in entirely different flex containers, so a single shared element
can't straddle both. */
.title-density-toggle {
  display: none;
  align-self: center;
  margin-left: auto;
  /* Nudges it a touch further left than flush-right so it sits centered
  over whatever it's floating below it, whenever this copy is flush
  against the title row's own right edge. */
  margin-right: 5px;
}

/* The filters form (and its own density-toggle-slot copy) doesn't render
at all while collapsed, regardless of width - without this the density toggle
would vanish along with it outside the narrow bands below that already
show this copy. Density affects the results grid, not the filters, so
hiding filters shouldn't hide it too. */
.title-row.filters-collapsed .title-density-toggle {
  display: block;
}

/* position: relative anchors .filters-toggle-floating, same as .filters
itself - the button isn't a flex participant here either, so this no
longer needs justify-content: space-between to push it to the far side. */
.filters-summary {
  position: relative;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.filters-summary p {
  margin: 0;
}

/* 481-1023px: whenever the form is expanded at anything narrower than
wide desktop but wider than phone width, the toggle lives next to the
title instead of embedded in the form - used to be three separate tiers
(481-806px, 807-981px, 982-1023px) each reordering the form's fields to
tuck the toggle in wherever it happened to fit (sharing a line with
Ordenar por, or with Minutos, depending on the tier), until it was
asked to just live at the title consistently across all of them instead
- confirmed with real (not simulated) windows at 768px, 820px, 987px
and 1020px. Below 481px the form's own layout gets tight enough (see
the ≤480px tier further down) that embedding it back in the form was
kept as-is there rather than also verifying a title-row version at
those narrower widths. */
@media (min-width: 481px) and (max-width: 1023px) {
  .title-density-toggle {
    display: block;
  }

  /* .filters > div (unconditional, no media query) sets display: flex
  on this same element with higher specificity (class + element vs.
  just a class) than a plain .density-toggle-slot selector here would
  have - matching its ".filters > " prefix is what actually outweighs
  it, same fix as everywhere else this page hides that slot. */
  .filters > .density-toggle-slot {
    display: none;
  }
}

/* 807-972px: asked for directly, to group Minutos disponibles and Modo
onto their own shared row with Ordenar por pushed after them to a lone
row of its own, rather than the plain default order (Ordenar por first,
then Minutos/Modo) this range had before. Capped at 972px rather than
carrying through to 981px so this doesn't fight the 973-981px tier
below, which already has its own tested reason to keep Minutos and Modo
apart instead.

Minutos disponibles' 5 radios don't fit next to Modo's own ~346px on
one line at this range's real content width (measured directly at
820px: 528px unconstrained, against only ~770px total to share with
Modo) - max-width: 350px forces its own radios to wrap onto two lines
instead (Cualquiera/Hasta 30 min/Hasta 1h, then Hasta 1h30/Hasta 2h),
confirmed stable across the 310-420px range so 350px isn't shaving
against an edge. */
@media (min-width: 807px) and (max-width: 972px) {
  .filters > .duration-field {
    order: 1;
    max-width: 350px;
  }

  .filters > .mode-fieldset {
    order: 2;
  }

  .filters > .sort-field {
    order: 3;
  }
}

/* 973-981px: right at the top of the 807-972px tier above (which this
range deliberately sits just outside of), Minutos and Modo are just
wide enough to still fit side by side on their own row without needing
Minutos' own radios to wrap the way the tier below forces.
Forcing that apart with width: 100% on Minutos (the same trick the
≤480px tier uses on .mode-fieldset) stretched Minutos itself to fill
the whole line instead of sitting at its own natural width - fine for
Modo there since it's meant to spread its radios across the row anyway,
but wrong here since Minutos' radios stay left-packed regardless,
leaving the stretched portion visibly empty. A zero-height ::before/
::after pair instead forces the same two line breaks (one before
Minutos, one after) without touching Minutos' own width at all - each
is a real flex item once it has content: '', so flex-basis: 100% on it
forces whatever comes next onto a fresh line the same way a genuinely
full-width item would, but the break itself renders as nothing between
the visible rows it separates. Modo, Ordenar por and the toggle (the
last one hidden in this range - see the 807-1023px rule above, which
this range sits inside of) each need an explicit order of their own
too, past the two breaks. */
@media (min-width: 973px) and (max-width: 981px) {
  /* Each break is its own zero-height flex line, and .filters' own
  row-gap applies between every pair of lines regardless of what's on
  them - two extra break lines otherwise add two extra gaps' worth of
  vertical space that the ≤972px layout (which doesn't need any breaks)
  never has. row-gap itself is turned off further down (see the comment
  by .filters there for why it has to live after .filters' own
  unconditional gap: shorthand rather than here) and added back by hand
  as margin-bottom, only on each row's own trailing item(s) - that
  reproduces the normal single gap between the three real rows without
  the breaks contributing any of their own, since margin on an item
  genuinely collapses away when nothing follows it on that line, unlike
  row-gap which can't tell a real line from a spacer one. */
  .filters > .search-field,
  .filters > .search-field + div,
  .filters > .structure-field,
  .filters > .genre-field {
    margin-bottom: var(--space-4);
  }

  .filters::before {
    content: '';
    order: 1;
    flex-basis: 100%;
    height: 0;
  }

  .filters > .duration-field {
    order: 2;
    margin-bottom: var(--space-4);
  }

  .filters::after {
    content: '';
    order: 3;
    flex-basis: 100%;
    height: 0;
  }

  .filters > .mode-fieldset {
    order: 4;
  }

  .filters > .sort-field {
    order: 5;
  }

  .filters > .density-toggle-slot {
    order: 6;
  }
}

@media (max-width: 480px) {
  /* Narrower than the usual 180px cap, just enough that Buscar still
  fits next to Jugadores at the narrowest phone widths this card has to
  support (iPhone SE's 375px) instead of wrapping onto its own line - a
  plain class selector wouldn't beat .filters > div's own specificity
  (class + element), hence matching its ".filters > " prefix here too.
  160px rather than a tighter fit is deliberate: wide enough that the
  "Nombre del juego…" placeholder reads almost in full instead of
  truncating hard. */
  .filters > .search-field {
    max-width: 160px;
  }

  .filters > .genre-field {
    max-width: 140px;
  }

  /* Fieldset, so .filters > div's max-width: 180px never applied here
  to begin with - it was sizing to its own content (legend + checkbox
  label) instead, which happened not to line up with .search-field's
  column above it once that grew to 160px. A fixed width (rather than
  max-width) forces the match instead of just capping it, since content
  alone wouldn't stretch it that wide. */
  .filters > .structure-field {
    width: 160px;
  }

  /* Pushed to the end via order, past Minutos and Modo (both still
  default order: 0, so they keep their DOM position) - moves it off its
  old spot above Minutos and lets it share the last row with the density
  toggle instead (order: 2 below), rather than physically relocating the
  markup and disturbing wider layouts where this media query doesn't
  apply. Widened past the usual 180px cap for the same reason as before
  - room for "Ranking BGG" (135px measured) to read in full - with the
  density toggle's own ~40px plus the row gap still comfortably fitting
  alongside it in the ~343-363px this card has at these widths. */
  .filters > .sort-field {
    order: 1;
    max-width: 260px;
  }

  .filters > .sort-field select {
    width: 9rem;
  }

  /* Order 2, right after .sort-field's order: 1 above - together they
  land on the row Modo's own width: 100% forces below it, sitting side
  by side as the last thing in the card instead of density-toggle-slot's
  original spot right after Modo in DOM order (still true above this
  breakpoint, where order isn't set). margin-left: auto pushes it flush
  against the card's right edge instead of sitting wherever it lands
  right after .sort-field, using up whatever's left of the row instead
  of the ~40px gap that would otherwise sit unused past it. Its own
  .filter-label-spacer (unchanged) still matches the "Ordenar por" label
  above .sort-row, so the button already starts level with .sort-row -
  the margin-top below only corrects the last bit: .sort-row is taller
  than the button (42.6px vs 32px, since .sort-toggle's padding makes it
  the taller of the two), so without it the button sits flush with the
  row's top instead of centered in its height. */
  .filters > .density-toggle-slot {
    order: 2;
    margin-left: auto;
  }

  .filters > .density-toggle-slot :deep(.density-toggle) {
    margin-top: 5px;
  }

  /* Trims the fieldset's own padding, and stretches it to the row's
  full width instead of shrink-wrapping its content - between the two,
  the labels fit one line even on iPhone SE's 375px without needing to
  touch each label's own icon-to-text gap (still the usual space-2,
  same as every other fieldset). justify-content: space-between (rather
  than a fixed gap) spreads the three across whatever width that ends
  up being instead of leaving them bunched at the left edge with the
  same cramped gap regardless of how much slack a wider phone actually
  has - gap here is only the floor space-between won't shrink below,
  not the real spacing at most widths. */
  .mode-fieldset {
    width: 100%;
    box-sizing: border-box;
    justify-content: space-between;
    gap: var(--space-1);
    padding: var(--space-1) var(--space-2);
  }
}

/* Only iPhone SE's 375px is actually this tight - everything from
~380px up already fits with each label's usual icon-to-text gap
(space-2, same as every other fieldset), so narrowing it much further
down than 480px keeps that default everywhere it's not strictly
needed. */
@media (max-width: 379px) {
  .mode-fieldset label {
    gap: 2px;
  }

  /* Buscar/Jugadores don't clear a real 375px window's own measured
  309px budget at Estructura's 160px (160+8+142.8 comes to 311px) -
  144px keeps real margin at both that and a real 366px phone's own
  tighter budget, without touching Estructura/Genero, which already
  pair correctly as-is at this width. */
  .filters > .search-field {
    max-width: 144px;
  }
}

/* At a real 366px phone specifically (9px tighter than 375px), even
Estructura/Genero stop fitting together - 160+8+140 needs 308px against
this card's real ~300px budget there, so Genero was dropping to its
own third line instead of sharing Estructura's. 144px (matching
Buscar's own width above, so the two columns still line up) is what
actually clears it. min-width: 0 is needed too - fieldset gets an
automatic minimum size from its own content (legend + checkbox label),
which a plain width smaller than that content can't shrink past on its
own, same issue hit before at an even narrower width. Wrapping "Modo
campaña" onto two lines (overriding its nowrap) is what lets it
actually fit in the narrower box instead of just overflowing it. */
@media (max-width: 366px) {
  .filters > .structure-field {
    width: 144px;
    min-width: 0;
  }

  .checkbox-label {
    white-space: normal;
    min-width: 0;
  }

  /* Minutos disponibles' 5 radios wrap into 3 lines at this card's real
  249px content width (measured directly - the ~268px this used to
  assume was never actually right) with the fieldset's usual space-4
  gap between labels and each label's own default space-2 icon-to-text
  gap: Hasta 1h + Hasta 1h30 + Hasta 2h needs 271px that way, well past
  budget. Tightening the between-labels gap to space-2 alone (256.66px)
  still doesn't clear it - the icon-to-text gap needs shrinking too
  (same fix .mode-fieldset label already used below), which gets the
  three down to 238.66px, comfortably inside the real budget. */
  .duration-field {
    gap: var(--space-2);
  }

  .duration-field label {
    gap: 2px;
  }

  /* Same real-measurement correction as Minutos disponibles above:
  Cualquiera/Cooperativo/Competitivo need 270.3px against this card's
  measured 265px content width even with the icon-to-text gap already
  at 2px (see the ≤379px rule below) - short by only ~5px on paper,
  which dropping the (mostly redundant once all three fit anyway -
  justify-content: space-between, set at ≤480px, spreads whatever
  room is left between them regardless of this value) between-labels
  gap to 0 should have cleared with a couple px to spare. It didn't on
  a real phone (reported directly, with a screenshot) - a ~2-3px
  margin measured against a desktop browser's own text rendering
  isn't real margin, evidently, so this now shaves off real width
  from two places at once instead of leaving it that close again:
  Modo's own horizontal padding (8px each side, set at ≤480px) drops
  to 4px, and the icon-to-text gap tightened to 2px above goes to 0
  here specifically. Comfortably over 15px of slack either way now,
  not a couple. */
  .mode-fieldset {
    gap: 0;
    padding-left: var(--space-1);
    padding-right: var(--space-1);
  }

  .mode-fieldset label {
    gap: 0;
  }
}

/* At a real 360px phone (6px tighter than 366px), Buscar/Jugadores
stop clearing this card's own budget at Buscar's 144px (144+8+142.8
comes to 294.8px against a ~294px budget there) - Estructura/Genero
still fit fine as-is (144+8+140 = 292px), so only Buscar needs to give
up a bit more. 140px (matching Genero's own width too) is what
actually clears it. */
@media (max-width: 360px) {
  .filters > .search-field {
    max-width: 140px;
  }
}

.filters {
  display: flex;
  /* Tighter than the space-6 this used before - freeing up a few pixels
  per gap is what lets Ordenar por fit on the same row as the rest of the
  top-line filters instead of wrapping to its own line. */
  gap: var(--space-4);
  flex-wrap: wrap;
  align-items: flex-start;
  margin-bottom: var(--space-6);
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
807-972px tier above though without that tier's own width cap on
Minutos - this range's narrower widths don't leave room to force
Minutos and Modo sharing a line the way that tier does. */
@media (min-width: 481px) and (max-width: 806px) {
  /* 160px rather than the 179px this row technically had left after
  Buscar/Jugadores/Estructura (measured via content-width simulation) -
  that left only ~9px of slack, and this page has already hit the real
  vs. simulated gap enough times elsewhere to not trust a margin that
  thin holding up on an actual device. */
  .filters > .genre-field {
    max-width: 160px;
  }

  .filters > .sort-field {
    order: 1;
  }
}

/* 674-765px: right in the middle of the 481-806px tier above, Buscar/
Jugadores/Estructura/Genero are too tight a fit on one line to want
them squeezed there rather than split. Estructura and Genero move down
to their own second row instead - a single ::before break forces that
split; unlike the 973-981px tier's two-break case, Minutos (wide enough
on its own to always force its own line regardless of what precedes it)
doesn't need a break of its own, it just naturally falls in after -
Modo and Ordenar por get their own explicit order below instead, since
this range needs its own line breaks regardless of whatever the parent
tier above does or doesn't reorder. row-gap is turned off the same way
and cascade-order reason as the 973-981px tier's own version further
down, and restored by hand as margin-bottom on each row's own trailing
item(s) instead - except here every row needs it, not just the one
next to the break, since row-gap being off applies to every line in
the card, not only the split one.

626-673px reuses the exact same split, asked for directly alongside
moving the toggle up to the title row (see that rule above) - giving
.density-toggle-slot an order here is harmless even though it's hidden
in this range, so sharing one block for both ranges is simpler than
duplicating it. */
@media (min-width: 674px) and (max-width: 765px), (min-width: 626px) and (max-width: 673px) {
  .filters > .search-field,
  .filters > .search-field + div {
    margin-bottom: var(--space-4);
  }

  .filters::before {
    content: '';
    order: 1;
    flex-basis: 100%;
    height: 0;
  }

  .filters > .structure-field {
    order: 2;
    margin-bottom: var(--space-4);
  }

  .filters > .genre-field {
    order: 2;
    margin-bottom: var(--space-4);
  }

  .filters > .duration-field {
    order: 3;
    margin-bottom: var(--space-4);
  }

  .filters > .mode-fieldset {
    order: 4;
  }

  .filters > .sort-field {
    order: 5;
  }

  .filters > .density-toggle-slot {
    order: 6;
  }
}

/* Placed after .filters itself (not inside the ≤480px block above) so
its column-gap wins the cascade tie against .filters' own unconditional
gap: shorthand above - same specificity either way (both just
".filters"), so source order is what decides it, and a media query
alone doesn't add any. Tighter than the row gap (still space-4,
untouched - only column-gap is overridden) - search-field growing to
160px at 380-480px leaves Buscar tight against Jugadores without it. At
379px and below, .search-field alone drops back to 144px (see the
media block further up) instead - Estructura/Genero already pair fine
at their own 160px/140px without needing a narrower gap - but the
tighter gap here still gives Buscar/Jugadores a bit more slack on top
of that than the 16px default would. */
@media (max-width: 480px) {
  .filters {
    column-gap: var(--space-2);
  }
}

/* Same cascade-order reasoning as the column-gap override above - has
to live after .filters' own unconditional gap: shorthand to win the
tie. Paired with the margin-bottom rules in the 973-981px block further
up, which restore a normal-looking single gap by hand between the
three real rows there instead. */
@media (min-width: 973px) and (max-width: 981px) {
  .filters {
    row-gap: 0;
  }
}

/* Same cascade-order reasoning, paired with the 674-765px/626-673px
block's own margin-bottom rules instead. */
@media (min-width: 674px) and (max-width: 765px), (min-width: 626px) and (max-width: 673px) {
  .filters {
    row-gap: 0;
  }
}

.filters > div {
  display: flex;
  flex-direction: column;
  max-width: 180px;
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

/* Fixed and deliberately tight - just enough for "Nombre" (the default
option) to read in full. A <select> otherwise sizes itself to its widest
option regardless of which one is picked (so "Ranking BGG" was pushing
this past the same 180px cap every other filter column keeps to, and
overflowing the card entirely once that cap was removed to compensate).
"Ranking BGG" clipping when picked is an accepted trade-off between the
480px breakpoint below and the desktop-only override further down,
where .filters > .sort-field select above widens it enough to show in
full instead. */
.sort-row select {
  width: 6.5rem;
  flex-shrink: 0;
}

/* None of the existing breakpoints reach past 1023px (the highest upper
bound among them), so this can't shrink anything they already cover -
picks back up right where they leave off. Same 9rem the ≤480px fix
above already uses, wide enough for "Ranking BGG" (135px measured) to
read in full at max desktop width instead of clipping. */
@media (min-width: 1024px) {
  .sort-row select {
    width: 9rem;
  }
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

.results :deep(.edit-icon-button) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text-muted);
}

.results :deep(.edit-icon-button:hover) {
  background: var(--color-surface-hover);
  color: var(--color-text);
}

.results :deep(.edit-icon-button svg) {
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
