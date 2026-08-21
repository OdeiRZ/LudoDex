<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { usePlaysStore, type Play } from '@/stores/plays'
import { FALLBACK_ICON_URL } from '@/lib/assets'
import GameDetailModal from '@/components/GameDetailModal.vue'

const plays = usePlaysStore()

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

onMounted(() => {
  if (!plays.loaded) {
    plays.fetchPage(1)
  }
})
</script>

<template>
  <div class="plays">
    <h1>{{ $t('plays.title') }}</h1>

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

    <p v-if="plays.loaded && plays.entries.length === 0 && !plays.search" class="empty-state">
      {{ $t('plays.empty') }}<br />
      <RouterLink :to="{ name: 'import-bgg', query: { tab: 'plays' } }">{{
        $t('plays.importLink')
      }}</RouterLink>.
    </p>

    <p v-else-if="plays.loaded && plays.entries.length === 0" class="empty-state">
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
      class="btn btn-secondary load-more"
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
  margin-bottom: var(--space-4);
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

/* A running count across every loaded page (asked for directly), not
per-page - "load more" appends to the same plays.entries array rather
than replacing it, so the v-for's own index already counts continuously
without needing separate state to track it. Fixed width (rather than
just a gap) keeps every row's own cover/name lined up regardless of how
many digits a given index needs (1 vs 100+). */
.play-index {
  width: 1.5rem;
  flex-shrink: 0;
  text-align: right;
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
.play-cover {
  width: 56px;
  height: 56px;
  object-fit: cover;
  border-radius: var(--radius);
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
