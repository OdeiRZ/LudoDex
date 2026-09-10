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

// Only for "Eliminar" (an established relationship) - declining an
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

// Same click-again-to-confirm pattern as onRemoveFriendClick above -
// blocking deletes any existing friendship/request too (see
// BlockService::block() on the backend), so it's at least as
// consequential as "eliminar amigo" and deserves the same safety net,
// not the single unconfirmed click it had before.
const confirmingBlockId = ref<number | null>(null)
let confirmingBlockTimeout: ReturnType<typeof setTimeout> | undefined

function onBlockClick(target: Friend) {
  if (confirmingBlockId.value !== target.id) {
    clearTimeout(confirmingBlockTimeout)
    confirmingBlockId.value = target.id
    confirmingBlockTimeout = setTimeout(() => {
      confirmingBlockId.value = null
    }, 4000)
    return
  }

  clearTimeout(confirmingBlockTimeout)
  confirmingBlockId.value = null
  onBlock(target)
}

onUnmounted(() => clearTimeout(confirmingBlockTimeout))

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
            class="btn btn-primary icon-btn"
            :aria-label="$t('friends.incoming.accept')"
            :title="$t('friends.incoming.accept')"
            :disabled="acceptingId === entry.id"
            @click="onAccept(entry.id)"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
            <span class="action-text">{{ $t('friends.incoming.accept') }}</span>
          </button>
          <button
            type="button"
            class="btn btn-danger icon-btn"
            :aria-label="$t('friends.incoming.decline')"
            :title="$t('friends.incoming.decline')"
            :disabled="removingId === entry.id"
            @click="onRemove(entry.id)"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M18 6L6 18M6 6l12 12" />
            </svg>
            <span class="action-text">{{ $t('friends.incoming.decline') }}</span>
          </button>
          <button
            type="button"
            class="btn btn-warning icon-btn"
            :class="{ 'btn-warning-confirm': confirmingBlockId === entry.user.id }"
            :aria-label="
              confirmingBlockId === entry.user.id
                ? $t('friends.incoming.blockConfirm')
                : $t('friends.incoming.block')
            "
            :title="
              confirmingBlockId === entry.user.id
                ? $t('friends.incoming.blockConfirm')
                : $t('friends.incoming.block')
            "
            :disabled="blockingUserId === entry.user.id"
            @click="onBlockClick(entry.user)"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <circle cx="12" cy="12" r="9" />
              <path stroke-linecap="round" d="M5.5 5.5l13 13" />
            </svg>
            <span class="action-text">{{
              confirmingBlockId === entry.user.id
                ? $t('friends.incoming.blockConfirm')
                : $t('friends.incoming.block')
            }}</span>
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
            class="btn icon-btn"
            :aria-label="$t('friends.list.viewProfile')"
            :title="$t('friends.list.viewProfile')"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"
              />
              <circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span class="action-text">{{ $t('friends.list.viewProfile') }}</span>
          </RouterLink>
          <button
            type="button"
            class="btn btn-danger icon-btn"
            :class="{ 'btn-danger-confirm': confirmingRemoveId === entry.id }"
            :aria-label="
              confirmingRemoveId === entry.id
                ? $t('friends.list.removeConfirm')
                : $t('friends.list.remove')
            "
            :title="
              confirmingRemoveId === entry.id
                ? $t('friends.list.removeConfirm')
                : $t('friends.list.remove')
            "
            :disabled="removingId === entry.id"
            @click="onRemoveFriendClick(entry.id)"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" d="M4 7h16" />
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M6 7l1 13a2 2 0 002 2h6a2 2 0 002-2l1-13"
              />
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"
              />
            </svg>
            <span class="action-text">{{
              confirmingRemoveId === entry.id
                ? $t('friends.list.removeConfirm')
                : $t('friends.list.remove')
            }}</span>
          </button>
          <button
            type="button"
            class="btn btn-warning icon-btn"
            :class="{ 'btn-warning-confirm': confirmingBlockId === entry.user.id }"
            :aria-label="
              confirmingBlockId === entry.user.id
                ? $t('friends.list.blockConfirm')
                : $t('friends.list.block')
            "
            :title="
              confirmingBlockId === entry.user.id
                ? $t('friends.list.blockConfirm')
                : $t('friends.list.block')
            "
            :disabled="blockingUserId === entry.user.id"
            @click="onBlockClick(entry.user)"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <circle cx="12" cy="12" r="9" />
              <path stroke-linecap="round" d="M5.5 5.5l13 13" />
            </svg>
            <span class="action-text">{{
              confirmingBlockId === entry.user.id
                ? $t('friends.list.blockConfirm')
                : $t('friends.list.block')
            }}</span>
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

/* flex-wrap is what actually fixes the overflow - a row with 3 buttons
(incoming: aceptar/rechazar/bloquear, or friends list: ver colección/
eliminar/bloquear) plus a name had nowhere to shrink to, so the buttons
spilled out past the card's own right edge instead of dropping to a
second line (found live). */
.friend-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-2) var(--space-3);
  padding: var(--space-2) 0;
  border-bottom: 1px solid var(--color-border);
}

.friend-row:last-child {
  border-bottom: none;
}

/* Full text by default - icon-only (asked for directly) only kicks in at
510px or narrower, same breakpoint-swap pattern already used for
App.vue's own "Cerrar sesión". Above it these read exactly like any
other .btn: text plus the color that already carries the accept/
decline/block/remove distinction (btn-primary/btn-danger/btn-warning).
aria-label/title stay on the button regardless of width - redundant
with the visible text above 510px, but exactly what carries the
accessible name and hover tooltip once the text itself hides below it. */
.icon-btn svg {
  display: none;
}

@media (max-width: 510px) {
  .icon-btn {
    padding: 0.55rem;
  }

  .icon-btn svg {
    display: block;
    width: 18px;
    height: 18px;
  }

  .icon-btn .action-text {
    display: none;
  }
}

/* min-width: 0 overrides the flex item's default content-based floor
(min-width: auto) - without it, a long name alone could still force the
row wider than its container even with flex-wrap above, the same
min-width gotcha already documented in FriendDetailView's own scrubber
comment. Truncates with an ellipsis instead. */
.friend-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 600;
}

/* .loading-state/.empty-state deliberately not redefined here - come
from the global main.css, same as every other view (found via a CSS-
consistency audit: this used to redefine both with a slightly
different look - no vertical centering/padding/text-align - than what
the same class names mean everywhere else in the app). */
</style>
