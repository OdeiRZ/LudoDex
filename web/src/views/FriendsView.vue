<script setup lang="ts">
import { onMounted, onUnmounted, reactive, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import { useFriendsStore, type Friend } from '@/stores/friends'
import { useToastStore } from '@/stores/toast'
import UserAvatar from '@/components/UserAvatar.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const friends = useFriendsStore()
const toast = useToastStore()
const { t } = useI18n()

onMounted(() => {
  if (!friends.loaded) {
    friends.fetchAll()
  }
})

const searchForm = reactive({ type: 'email' as 'email' | 'bgg_username', value: '' })
const searching = ref(false)
const searched = ref(false)
const searchResult = ref<Friend | null>(null)
const searchError = ref<string | null>(null)

async function onSearch() {
  searching.value = true
  searched.value = false
  searchResult.value = null
  searchError.value = null

  try {
    searchResult.value =
      searchForm.type === 'email'
        ? await friends.searchByEmail(searchForm.value)
        : await friends.searchByBggUsername(searchForm.value)
  } catch (err) {
    // A malformed email (or any other 422, e.g. neither field's format
    // rule passing) is a problem with what was typed, not a generic
    // failure - showing the backend's own validation message instead of
    // "algo ha ido mal" tells the user what to actually fix, same pattern
    // already used in onSendRequest() below.
    searchError.value =
      isAxiosError(err) && err.response?.status === 422
        ? Object.values(err.response.data.errors).flat().join(' ')
        : t('friends.search.genericError')
  } finally {
    searching.value = false
    searched.value = true
  }
}

const sendingRequestFor = ref<number | null>(null)
const requestError = ref<string | null>(null)

async function onSendRequest(target: Friend) {
  sendingRequestFor.value = target.id
  requestError.value = null

  try {
    await friends.sendRequest(target)
    searchResult.value = null
    searched.value = false
    searchForm.value = ''
  } catch (err) {
    requestError.value =
      isAxiosError(err) && err.response?.status === 422
        ? Object.values(err.response.data.errors).flat().join(' ')
        : t('friends.search.genericError')
  } finally {
    sendingRequestFor.value = null
  }
}

const acceptingId = ref<number | null>(null)
async function onAccept(requestId: number) {
  acceptingId.value = requestId
  try {
    await friends.acceptRequest(requestId)
  } catch {
    toast.show(t('friends.genericError'), 'error')
  } finally {
    acceptingId.value = null
  }
}

const removingId = ref<number | null>(null)
async function onRemove(friendshipId: number) {
  removingId.value = friendshipId
  try {
    await friends.removeRelationship(friendshipId)
  } catch {
    toast.show(t('friends.genericError'), 'error')
  } finally {
    removingId.value = null
  }
}

// Only for "Quitar amigo" (an established relationship) - declining an
// incoming request or cancelling an outgoing one stays a single click via
// onRemove() directly, same distinction DashboardView already draws
// between deleting a game (armed) and lighter actions (not). Same
// "click again within 4s" pattern as that page's own delete button.
const confirmingRemoveId = ref<number | null>(null)
let confirmingRemoveTimeout: ReturnType<typeof setTimeout> | undefined

function onRemoveFriendClick(friendshipId: number) {
  if (confirmingRemoveId.value !== friendshipId) {
    clearTimeout(confirmingRemoveTimeout)
    confirmingRemoveId.value = friendshipId
    confirmingRemoveTimeout = setTimeout(() => {
      confirmingRemoveId.value = null
    }, 4000)
    return
  }

  clearTimeout(confirmingRemoveTimeout)
  confirmingRemoveId.value = null
  onRemove(friendshipId)
}

onUnmounted(() => clearTimeout(confirmingRemoveTimeout))

const blockingUserId = ref<number | null>(null)
async function onBlock(target: Friend) {
  blockingUserId.value = target.id
  try {
    await friends.blockUser(target)
  } catch {
    toast.show(t('friends.genericError'), 'error')
  } finally {
    blockingUserId.value = null
  }
}

const unblockingId = ref<number | null>(null)
async function onUnblock(blockId: number) {
  unblockingId.value = blockId
  try {
    await friends.unblockUser(blockId)
  } catch {
    toast.show(t('friends.genericError'), 'error')
  } finally {
    unblockingId.value = null
  }
}
</script>

<template>
  <div class="friends">
    <h1>{{ $t('friends.title') }}</h1>

    <section class="card">
      <h2>{{ $t('friends.search.title') }}</h2>

      <form class="search-form" @submit.prevent="onSearch">
        <select v-model="searchForm.type" :aria-label="$t('friends.search.type')">
          <option value="email">{{ $t('friends.search.byEmail') }}</option>
          <option value="bgg_username">{{ $t('friends.search.byBggUsername') }}</option>
        </select>
        <input
          v-model="searchForm.value"
          type="text"
          required
          :placeholder="
            searchForm.type === 'email'
              ? $t('friends.search.emailPlaceholder')
              : $t('friends.search.bggUsernamePlaceholder')
          "
        />
        <button type="submit" class="btn btn-primary" :disabled="searching">
          {{ searching ? $t('friends.search.searching') : $t('friends.search.submit') }}
        </button>
      </form>

      <p v-if="searchError" role="alert" class="alert alert-error">{{ searchError }}</p>
      <p v-if="requestError" role="alert" class="alert alert-error">{{ requestError }}</p>

      <div v-if="searched && !searching && !searchError" class="search-result">
        <p v-if="!searchResult" role="status">{{ $t('friends.search.notFound') }}</p>
        <div v-else class="friend-row">
          <UserAvatar :name="searchResult.name" :avatar-url="searchResult.avatar_url" :size="40" />
          <span class="friend-name">{{ searchResult.name }}</span>
          <button
            type="button"
            class="btn btn-primary"
            :disabled="sendingRequestFor === searchResult.id"
            @click="onSendRequest(searchResult)"
          >
            {{ $t('friends.search.sendRequest') }}
          </button>
        </div>
      </div>
    </section>

    <div v-if="friends.loadError" class="load-error">
      <p role="alert" class="alert alert-error">{{ $t('common.loadError') }}</p>
      <button type="button" class="btn" @click="friends.fetchAll()">
        {{ $t('common.retry') }}
      </button>
    </div>

    <div v-else-if="!friends.loaded" class="loading-state"><LoadingSpinner :size="32" /></div>

    <template v-else>
      <section v-if="friends.incomingRequests.length > 0" class="card">
        <h2>{{ $t('friends.incoming.title') }}</h2>
        <div v-for="entry in friends.incomingRequests" :key="entry.id" class="friend-row">
          <UserAvatar :name="entry.user.name" :avatar-url="entry.user.avatar_url" :size="40" />
          <span class="friend-name">{{ entry.user.name }}</span>
          <button
            type="button"
            class="btn btn-primary"
            :disabled="acceptingId === entry.id"
            @click="onAccept(entry.id)"
          >
            {{ $t('friends.incoming.accept') }}
          </button>
          <button
            type="button"
            class="btn"
            :disabled="removingId === entry.id"
            @click="onRemove(entry.id)"
          >
            {{ $t('friends.incoming.decline') }}
          </button>
          <button
            type="button"
            class="btn"
            :disabled="blockingUserId === entry.user.id"
            @click="onBlock(entry.user)"
          >
            {{ $t('friends.incoming.block') }}
          </button>
        </div>
      </section>

      <section v-if="friends.outgoingRequests.length > 0" class="card">
        <h2>{{ $t('friends.outgoing.title') }}</h2>
        <div v-for="entry in friends.outgoingRequests" :key="entry.id" class="friend-row">
          <UserAvatar :name="entry.user.name" :avatar-url="entry.user.avatar_url" :size="40" />
          <span class="friend-name">{{ entry.user.name }}</span>
          <button
            type="button"
            class="btn"
            :disabled="removingId === entry.id"
            @click="onRemove(entry.id)"
          >
            {{ $t('friends.outgoing.cancel') }}
          </button>
        </div>
      </section>

      <section class="card">
        <h2>{{ $t('friends.list.title') }}</h2>
        <p v-if="friends.friends.length === 0" class="empty-state">
          {{ $t('friends.list.empty') }}
        </p>
        <div v-for="entry in friends.friends" :key="entry.id" class="friend-row">
          <UserAvatar :name="entry.user.name" :avatar-url="entry.user.avatar_url" :size="40" />
          <span class="friend-name">{{ entry.user.name }}</span>
          <RouterLink
            :to="{ name: 'friend-detail', params: { friendId: entry.user.id } }"
            class="btn"
          >
            {{ $t('friends.list.viewProfile') }}
          </RouterLink>
          <button
            type="button"
            class="btn btn-danger"
            :class="{ 'btn-danger-confirm': confirmingRemoveId === entry.id }"
            :disabled="removingId === entry.id"
            @click="onRemoveFriendClick(entry.id)"
          >
            {{
              confirmingRemoveId === entry.id
                ? $t('friends.list.removeConfirm')
                : $t('friends.list.remove')
            }}
          </button>
          <button
            type="button"
            class="btn"
            :disabled="blockingUserId === entry.user.id"
            @click="onBlock(entry.user)"
          >
            {{ $t('friends.list.block') }}
          </button>
        </div>
      </section>

      <section v-if="friends.blockedUsers.length > 0" class="card">
        <h2>{{ $t('friends.blocked.title') }}</h2>
        <div v-for="entry in friends.blockedUsers" :key="entry.id" class="friend-row">
          <UserAvatar :name="entry.user.name" :avatar-url="entry.user.avatar_url" :size="40" />
          <span class="friend-name">{{ entry.user.name }}</span>
          <button
            type="button"
            class="btn"
            :disabled="unblockingId === entry.id"
            @click="onUnblock(entry.id)"
          >
            {{ $t('friends.blocked.unblock') }}
          </button>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.friends {
  max-width: 560px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
}

h1 {
  margin-bottom: var(--space-2);
}

h2 {
  margin-bottom: var(--space-4);
}

.search-form {
  display: flex;
  gap: var(--space-2);
  flex-wrap: wrap;
  margin-bottom: var(--space-4);
}

.search-form input {
  flex: 1;
  min-width: 160px;
}

.search-result {
  margin-top: var(--space-2);
}

.friend-row {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-2) 0;
  border-bottom: 1px solid var(--color-border);
}

.friend-row:last-child {
  border-bottom: none;
}

.friend-name {
  flex: 1;
  font-weight: 600;
}

/* .loading-state/.empty-state deliberately not redefined here - come
from the global main.css, same as every other view (found via a CSS-
consistency audit: this used to redefine both with a slightly
different look - no vertical centering/padding/text-align - than what
the same class names mean everywhere else in the app). */
</style>
