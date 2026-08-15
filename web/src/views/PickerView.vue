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

// Same criterion/order pair as the collection's own sort controls (and the
// same reasoning for a toggle button over a second radio group) - the
// collection can come back from the API in an order that has nothing to do
// with the name (BGG import order, insertion order...), so without this the
// results here could just as easily land reverse-alphabetical as not.
type SortCriterion = 'name' | 'rank'

const sortCriterion = ref<SortCriterion>('name')
const sortOrder = ref<'asc' | 'desc'>('asc')

function toggleSort() {
  sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
}

const sortToggleLabel = computed(() => {
  if (sortCriterion.value === 'rank') {
    return sortOrder.value === 'asc' ? '#1 → #N' : '#N → #1'
  }

  return sortOrder.value === 'asc' ? 'A → Z' : 'Z → A'
})

const sortToggleActionLabel = computed(() => {
  if (sortCriterion.value === 'rank') {
    return sortOrder.value === 'asc' ? t('dashboard.sortRankDesc') : t('dashboard.sortRankAsc')
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

const filtered = computed(() => {
  const minPlayersFilter = asFilterNumber(players.value)
  const maxDurationFilter = durationBucket.value === 'any' ? null : Number(durationBucket.value)
  const query = search.value.trim().toLowerCase()

  const base = playable.value.filter(({ game }) => {
    if (query !== '' && !game.name.toLowerCase().includes(query)) return false

    if (minPlayersFilter !== null) {
      if (game.min_players !== null && minPlayersFilter < game.min_players) return false
      if (game.max_players !== null && minPlayersFilter > game.max_players) return false
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

    if (onlyCampaign.value && !game.has_campaign) return false

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

  return base.sort((a, b) => {
    const cmp = a.game.name.localeCompare(b.game.name)
    return sortOrder.value === 'asc' ? cmp : -cmp
  })
})
</script>

<template>
  <div>
    <div class="title-row">
      <h1>{{ $t('picker.title') }}</h1>
      <span v-if="games.loaded && playable.length" class="count">{{
        $t('common.gamesCount', { count: filtered.length })
      }}</span>
      <div v-if="games.loaded && playable.length" class="title-density-toggle">
        <DensityToggle :density="density" @toggle="toggleDensity" />
      </div>
    </div>

    <form v-if="games.loaded && playable.length" class="filters card" @submit.prevent>
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
          <p v-if="entry.game.min_players || entry.game.max_players || entry.game.min_playtime_minutes || entry.game.max_playtime_minutes" class="meta">
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
          <p
            v-if="
              entry.game.is_cooperative ||
              entry.game.is_competitive ||
              entry.game.has_campaign ||
              entry.game.bgg_id !== null ||
              expansionCounts[entry.game.id]
            "
            class="tags"
          >
            <span v-if="entry.game.is_cooperative" class="badge badge-primary">{{ $t('picker.cooperative') }}</span>
            <span v-if="entry.game.is_competitive" class="badge badge-primary">{{ $t('picker.competitive') }}</span>
            <span v-if="entry.game.has_campaign" class="badge badge-accent">{{ $t('picker.campaign') }}</span>
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
}

.count {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

/* Hidden by default - only the .density-toggle-slot copy down in the
filters form shows outside the narrow band below. Same "two renditions,
toggle via CSS" pattern used everywhere else on this page: the two
spots sit in entirely different flex containers, so a single shared
element can't straddle both. */
.title-density-toggle {
  display: none;
  align-self: center;
  margin-left: auto;
}

/* 982-1023px: confirmed with real (not simulated) windows at 987px and
1020px, unlike an earlier attempt at this same gap that turned out to
be measured while the browser's real width had silently drifted
somewhere else entirely. Right in between the 821-981px tier above
(where Ordenar por joining the first row still leaves enough room for
Minutos/Modo/the toggle to share the second) and wide desktop (#app's
own max-width: 1024px caps the card's content width from here on, and
the toggle fits flush after Minutos/Modo once it does) - in this band
it's neither: Minutos and Modo together already fill the second row
that far, so the toggle drops to a lone third line, left-aligned and
stranded. Moving it up next to the title instead avoids that orphaned
line entirely, same technique as the two title/form pairs already used
elsewhere on this page.

626-673px gets the same treatment for a different reason - asked for
directly, to pair with the 674-765px tier's own row split just below
it (Estructura/Genero moving down to their own second row leaves even
less headroom for the toggle at the end here too). */
@media (min-width: 982px) and (max-width: 1023px), (min-width: 626px) and (max-width: 673px) {
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

/* 807-981px: at this width the card's default (unbounded) flex-wrap
already reflows into a usable 2-line shape on its own (Ordenar por
tags along on the first line with Buscar/Jugadores/Estructura/Genero,
Minutos/Modo/the toggle sharing the second) - nothing here was actually
broken. This reorders it into the layout asked for instead: Ordenar por
and the toggle pushed past Minutos and Modo (both left at their default
order: 0) to land together as the last line, flush against the card's
right edge - same order-based technique as the ≤480px and ≤768px tiers,
so wider layouts (where this media query doesn't apply) keep the plain
DOM order Ordenar por already had. 807px (rather than a rounder number)
is deliberate - confirmed with a real window right at that edge that
Modo, Ordenar por and the toggle still fit together on their own line
that far down without needing the 973-981px tier's line-break trick
further up. */
@media (min-width: 807px) and (max-width: 981px) {
  .filters > .sort-field {
    order: 1;
  }

  .filters > .density-toggle-slot {
    order: 2;
    margin-left: auto;
  }

  /* Same fix as the ≤480px/≤768px tiers: .sort-row (which
  .sort-toggle's own padding makes taller than a plain select) is
  taller than the density toggle button, so without this it sits flush
  with the row's top instead of centered against it. */
  .filters > .density-toggle-slot :deep(.density-toggle) {
    margin-top: 5px;
  }
}

/* 973-981px: right at the top of the 821-981px tier above, Minutos and
Modo are just wide enough to still fit side by side on their own row.
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
the visible rows it separates. Modo, Ordenar por and the toggle each
need an explicit order now too (rather than just Modo's) - Ordenar
por's order: 1/the toggle's order: 2 from the parent 821-981px rule
above would otherwise still outrank Modo's default order: 0 and land
before it. */
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
}

/* Tablet width - bounded on both ends (rather than reusing the ≤480px
block above or just adding a plain max-width) so it can't cascade
against or get cascaded over by that block's own order/width rules;
the two ranges never overlap, so which one comes first in the file
doesn't matter either way. At this width there's room for Buscar,
Jugadores, Estructura and Genero to all share the first row instead of
Genero wrapping alone. Ordenar por and the density toggle move to
order 1/2 for the same reason as the ≤480px tier: pushed past Minutos
and Modo (both still default order: 0) to land right after Modo
instead of its own natural spot ahead of Minutos - margin-left: auto on
the toggle below matches the ≤480px tier too, flush against the card's
right edge instead of sitting wherever it lands right after
.sort-field. Upper bound is 806px, not 768px - 769-806px used to fall
through to the plain unbounded default (Ordenar por landing next to
Minutos instead of Modo), which this tier's own reordering also fixes
just as well, so it's simplest to just extend this same tier over that
gap rather than add a third near-identical block. 807px is where the
next tier up (821px originally, later extended down) takes over
instead. */
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

  .filters > .density-toggle-slot {
    order: 2;
    margin-left: auto;
  }

  /* Same fix as the ≤480px tier: .sort-row (which .sort-toggle's own
  padding makes taller than a plain select) is taller than the density
  toggle button, so without this it sits flush with the row's top
  instead of centered against it. */
  .filters > .density-toggle-slot :deep(.density-toggle) {
    margin-top: 5px;
  }
}

/* 674-765px: right in the middle of the 481-806px tier above, Buscar/
Jugadores/Estructura/Genero are too tight a fit on one line to want
them squeezed there rather than split. Estructura and Genero move down
to their own second row instead - a single ::before break forces that
split; unlike the 973-981px tier's two-break case, Minutos (wide enough
on its own to always force its own line regardless of what precedes it)
and Modo/Ordenar por/the toggle (already reordered to follow it by the
parent tier above) don't need breaks of their own, they just naturally
fall in after. row-gap is turned off the same way and cascade-order
reason as the 973-981px tier's own version further down, and restored
by hand as margin-bottom on each row's own trailing item(s) instead -
except here every row needs it, not just the one next to the break,
since row-gap being off applies to every line in the card, not only
the split one.

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
"Ranking BGG" clipping when picked is an accepted trade-off above the
480px breakpoint, where .filters > .sort-field select below widens it
enough to show in full instead. */
.sort-row select {
  width: 6.5rem;
  flex-shrink: 0;
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

.results :deep(.badge-rank),
.results :deep(.badge-expansion) {
  background: rgba(255, 255, 255, 0.2);
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
