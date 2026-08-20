<script setup lang="ts">
import { onMounted } from 'vue'
import { usePlaysStore } from '@/stores/plays'
import { FALLBACK_ICON_URL } from '@/lib/assets'

const plays = usePlaysStore()

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
      <li v-for="play in plays.entries" :key="play.id" class="play-row">
        <img
          v-if="play.game.image_url"
          :src="play.game.image_url"
          alt=""
          class="play-cover"
        />
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
      v-if="plays.loaded && plays.currentPage < plays.lastPage"
      type="button"
      class="btn btn-secondary load-more"
      :disabled="plays.loading"
      @click="loadMore"
    >
      {{ $t('plays.loadMore') }}
    </button>
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

.play-cover {
  width: 40px;
  height: 40px;
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
