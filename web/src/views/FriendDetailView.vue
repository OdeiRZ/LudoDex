<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useFriendDetailStore } from '@/stores/friendDetail'
import { FALLBACK_ICON_URL } from '@/lib/assets'
import UserAvatar from '@/components/UserAvatar.vue'
import GameCard from '@/components/GameCard.vue'
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

const friendName = computed(() => friendDetail.friend?.name ?? '')

// Driven by data rather than three near-identical template blocks - the
// only real difference between the three sections is which array/i18n
// strings feed them, so a single v-for renders all three.
const collectionSections = computed(() => [
  {
    key: 'shared',
    title: t('friends.detail.collection.shared'),
    games: friendDetail.shared,
    empty: t('friends.detail.collection.emptyShared'),
  },
  {
    key: 'mineOnly',
    title: t('friends.detail.collection.mineOnly'),
    games: friendDetail.mineOnly,
    empty: t('friends.detail.collection.emptyMineOnly', { name: friendName.value }),
  },
  {
    key: 'theirsOnly',
    title: t('friends.detail.collection.theirsOnly', { name: friendName.value }),
    games: friendDetail.theirsOnly,
    empty: t('friends.detail.collection.emptyTheirsOnly', { name: friendName.value }),
  },
])

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
    <RouterLink :to="{ name: 'friends' }" class="back-link">
      {{ $t('friends.detail.backToList') }}
    </RouterLink>

    <div v-if="friendDetail.notFound" class="card not-found">
      <p>{{ $t('friends.detail.notFound') }}</p>
    </div>

    <template v-else>
      <div v-if="friendDetail.friend" class="friend-header">
        <UserAvatar
          :name="friendDetail.friend.name"
          :avatar-url="friendDetail.friend.avatar_url"
          :size="48"
        />
        <h1>{{ friendDetail.friend.name }}</h1>
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
        <p v-if="!friendDetail.collectionLoaded" class="loading-state">
          <LoadingSpinner :size="36" />
          {{ $t('friends.detail.collection.loading') }}
        </p>

        <template v-else>
          <section
            v-for="section in collectionSections"
            :key="section.key"
            class="collection-section"
          >
            <h2>{{ section.title }}</h2>
            <p v-if="section.games.length === 0" class="empty-state">{{ section.empty }}</p>
            <ul v-else class="games">
              <li v-for="game in section.games" :key="game.id" class="game-card">
                <GameCard :image-url="game.image_url" :is-expansion="game.base_game_id !== null">
                  <h3>{{ game.name }}</h3>
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
        </template>
      </template>

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
  </div>
</template>

<style scoped>
.friend-detail {
  max-width: 640px;
  margin: 0 auto;
}

.back-link {
  display: inline-block;
  margin-bottom: var(--space-4);
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.friend-header {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-4);
}

.friend-header h1 {
  margin: 0;
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

.loading-state {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--color-text-muted);
}

.collection-section {
  margin-bottom: var(--space-6);
}

.collection-section h2 {
  font-size: 1rem;
  margin-bottom: var(--space-3);
}

.empty-state {
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

.games :deep(h3) {
  font-size: 0.95rem;
  overflow-wrap: anywhere;
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
