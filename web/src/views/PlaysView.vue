<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { usePlaysStore, type Play } from '@/stores/plays'
import { FALLBACK_ICON_URL } from '@/lib/assets'
import GameDetailModal from '@/components/GameDetailModal.vue'

const plays = usePlaysStore()

// Which play's own game detail is open, if any - same "one at a time"
// pattern as the picker/dashboard's own detailEntry.
const detailGame = ref<Play['game'] | null>(null)

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

    <p v-if="plays.loaded && plays.entries.length === 0" class="empty-state">
      {{ $t('plays.empty') }}<br />
      <RouterLink :to="{ name: 'import-bgg', query: { tab: 'plays' } }">{{
        $t('plays.importLink')
      }}</RouterLink>.
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

    <GameDetailModal v-if="detailGame" :game="detailGame" @close="detailGame = null" />
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
