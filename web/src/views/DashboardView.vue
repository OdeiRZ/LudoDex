<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useGamesStore } from '@/stores/games'
import { useToastStore } from '@/stores/toast'
import { useCollectionDensity } from '@/composables/useCollectionDensity'
import DensityToggle from '@/components/DensityToggle.vue'
import GameCard from '@/components/GameCard.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const auth = useAuthStore()
const games = useGamesStore()
const toast = useToastStore()
const { t } = useI18n()

onMounted(() => {
  if (!auth.user) {
    auth.fetchCurrentUser()
  }

  if (!games.loaded) {
    games.fetchAll()
  }
})

// Client-side against the collection already loaded in the store, same as
// the picker's filters - a real collection can run into the hundreds of
// games (see the BGG CSV import), and finding one by scrolling stopped
// being practical well before that.
const search = ref('')

// Alphabetical only for now - other criteria (e.g. weight) can follow later
// once there's a real need for them.
const sortOrder = ref<'asc' | 'desc'>('asc')

function toggleSort() {
  sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
}

const filtered = computed(() => {
  const query = search.value.trim().toLowerCase()

  // .filter() already returns a fresh array, but the no-query branch
  // needs its own copy too - sorting games.collection directly would
  // mutate the store's own array in place instead of just this view.
  const base = query
    ? games.collection.filter((entry) => entry.game.name.toLowerCase().includes(query))
    : [...games.collection]

  return base.sort((a, b) => {
    const cmp = a.game.name.localeCompare(b.game.name)
    return sortOrder.value === 'asc' ? cmp : -cmp
  })
})

const { density, toggle: toggleDensity } = useCollectionDensity()

async function onDelete(userGameId: string) {
  await games.deleteGame(userGameId)
  toast.show(t('dashboard.toastRemoved'))
}

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
  } finally {
    clearing.value = false
  }
}
</script>

<template>
  <div>
    <div class="header">
      <div class="title-row">
        <h1>{{ $t('dashboard.title') }}</h1>
        <span v-if="games.loaded" class="count">{{
          $t('common.gamesCount', { count: filtered.length })
        }}</span>
      </div>
      <button
        v-if="games.loaded && games.collection.length > 0"
        type="button"
        class="btn btn-danger clear-library-btn"
        @click="openClearConfirm"
      >
        {{ $t('dashboard.clearLibrary') }}
      </button>
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
      <div class="search-row">
        <input
          v-model="search"
          type="search"
          :aria-label="$t('dashboard.searchLabel')"
          :placeholder="$t('dashboard.searchPlaceholder')"
        />
        <button
          type="button"
          class="btn sort-toggle"
          :aria-label="sortOrder === 'asc' ? $t('dashboard.sortDesc') : $t('dashboard.sortAsc')"
          :title="sortOrder === 'asc' ? $t('dashboard.sortDesc') : $t('dashboard.sortAsc')"
          @click="toggleSort"
        >
          {{ sortOrder === 'asc' ? 'A → Z' : 'Z → A' }}
        </button>
        <DensityToggle :density="density" @toggle="toggleDensity" />
        <RouterLink :to="{ name: 'add-game' }" class="btn btn-primary add-game-btn">{{
          $t('dashboard.addGame')
        }}</RouterLink>
      </div>

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
        <GameCard :image-url="entry.game.image_url" :compact="density === 'compact'">
          <h2>{{ entry.game.name }}</h2>
          <div class="badge-row">
            <span class="badge" :class="entry.status === 'owned' ? 'badge-primary' : 'badge-accent'">
              {{ entry.status === 'owned' ? $t('dashboard.owned') : $t('dashboard.wishlist') }}
            </span>
            <span v-if="entry.game.bgg_id !== null" class="badge badge-rank">
              {{
                entry.game.bgg_rank !== null
                  ? $t('dashboard.rank', { rank: entry.game.bgg_rank })
                  : $t('dashboard.unranked')
              }}
            </span>
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
          <div class="card-actions">
            <RouterLink
              :to="{ name: 'edit-game', params: { id: entry.id }, query: { from: 'dashboard' } }"
              class="btn"
            >
              {{ $t('dashboard.edit') }}
            </RouterLink>
            <button type="button" class="btn btn-danger" @click="onDelete(entry.id)">
              {{ $t('dashboard.remove') }}
            </button>
          </div>
        </GameCard>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--space-6);
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

.search-row {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-4);
  flex-wrap: wrap;
}

.search-row input {
  max-width: 320px;
}

.add-game-btn {
  margin-left: auto;
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

.games :deep(h2) {
  font-size: 1.05rem;
  overflow-wrap: anywhere;
}

/* Without this the badge row stretches to the scrim's full width, since
it's a direct child of a column flex container (align-items: stretch by
default) - the picker's badges look right because theirs sit inside their
own row flex wrapper (.tags) instead of directly in the scrim. */
.games :deep(.badge-row) {
  display: flex;
  align-self: flex-start;
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

.games :deep(.badge-rank) {
  background: rgba(255, 255, 255, 0.2);
  color: #fff;
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

/* At the compact grid's narrower card width, "Editar"/"Quitar" side by
side at their normal padding/font-size don't fit - the card's own
overflow: hidden then clips "Quitar" instead of letting it wrap or
overflow visibly. */
.games.compact :deep(.card-actions .btn) {
  padding: 0.35rem 0.5rem;
  font-size: 0.8rem;
}
</style>
