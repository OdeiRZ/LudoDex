<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { isAxiosError } from 'axios'
import { useGamesStore, type BggImportStatus, type BggCsvImportResult } from '@/stores/games'
import { usePlaysStore, type PlaysImportResult } from '@/stores/plays'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const games = useGamesStore()
const plays = usePlaysStore()
const { t } = useI18n()
const route = useRoute()

const method = ref<'username' | 'csv' | 'plays'>(route.query.tab === 'plays' ? 'plays' : 'username')

const username = ref('')
const phase = ref<'idle' | 'pending' | 'completed' | 'failed'>('idle')
const importedCount = ref<number | null>(null)
const errorMessage = ref<string | null>(null)
const submitting = ref(false)

const POLL_INTERVAL_MS = 3000
let pollTimer: ReturnType<typeof setTimeout> | undefined

// BGG's own export can take minutes for a large collection, and nothing
// here survives a reload on its own - a backgrounded tab getting suspended
// (or just reloaded to free memory) loses all local state, so without this
// the only sign the user gets back is a blank form, with no idea an import
// is still quietly working server-side. Persisting just the id (not the
// whole in-progress state) lets onMounted below pick the polling back up
// instead of inviting a redundant, forgotten-about second attempt.
const PENDING_IMPORT_KEY = 'ludodex_pending_bgg_import'

function savePendingImportId(id: string) {
  localStorage.setItem(PENDING_IMPORT_KEY, id)
}

function clearPendingImportId() {
  localStorage.removeItem(PENDING_IMPORT_KEY)
}

function handleResult(result: BggImportStatus) {
  phase.value = result.status

  if (result.status === 'pending') {
    savePendingImportId(result.id)
    pollTimer = setTimeout(() => poll(result.id), POLL_INTERVAL_MS)
    return
  }

  clearPendingImportId()
  importedCount.value = result.imported_count
  errorMessage.value = result.error_message

  if (result.status === 'completed') {
    games.fetchAll()
  }
}

async function poll(id: string) {
  try {
    const result = await games.pollBggImport(id)
    handleResult(result)
  } catch (err) {
    if (isAxiosError(err) && err.response) {
      // A real response came back and it wasn't OK (e.g. this id doesn't
      // belong to us, or the row is gone) - stop chasing a stale id
      // instead of retrying it forever.
      clearPendingImportId()
      phase.value = 'failed'
      errorMessage.value = t('importBgg.genericFailedError')
      return
    }

    // No response at all - a dropped connection, or the tab just resumed
    // from being backgrounded/a free-tier cold start - transient, so keep
    // trying rather than losing track of an import that may still be
    // working fine on BGG's own side.
    pollTimer = setTimeout(() => poll(id), POLL_INTERVAL_MS)
  }
}

async function onSubmit() {
  submitting.value = true
  phase.value = 'pending'
  errorMessage.value = null

  await attemptStart(username.value)

  submitting.value = false
}

async function attemptStart(usernameValue: string) {
  try {
    const result = await games.startBggImport(usernameValue)
    handleResult(result)
  } catch (err) {
    if (isAxiosError(err) && err.response) {
      phase.value = 'failed'
      errorMessage.value = t('importBgg.genericStartError')
      return
    }

    // Same reasoning as poll()'s own retry: a dropped connection or a tab
    // resuming from being backgrounded shouldn't read as "couldn't start
    // the import" when it may well have gone through - the backend reuses
    // a still-pending import for this username instead of starting a
    // duplicate, so retrying here is safe.
    pollTimer = setTimeout(() => attemptStart(usernameValue), POLL_INTERVAL_MS)
  }
}

onUnmounted(() => {
  if (pollTimer) {
    clearTimeout(pollTimer)
  }
})

// CSV import is a separate, independent flow: parsing a file is
// synchronous (no BGG 202-while-queued state to poll), so it only needs
// idle/submitting/done/failed, not the pending/poll machinery above.
const csvFile = ref<File | null>(null)
const csvSubmitting = ref(false)
const csvResult = ref<BggCsvImportResult | null>(null)
const csvErrorMessage = ref<string | null>(null)

function onCsvFileChange(event: Event) {
  csvFile.value = (event.target as HTMLInputElement).files?.[0] ?? null
}

async function onCsvSubmit() {
  if (!csvFile.value) {
    return
  }

  csvSubmitting.value = true
  csvErrorMessage.value = null

  try {
    csvResult.value = await games.importBggCsv(csvFile.value)
    games.fetchAll()
  } catch {
    csvErrorMessage.value = t('importBgg.csvGenericError')
  } finally {
    csvSubmitting.value = false
  }
}

// Plays import is synchronous too (same reasoning as CSV above - /plays
// has no BGG-side async export step to poll), just driven by a username
// like the collection tab instead of a file.
const playsUsername = ref('')
const playsSubmitting = ref(false)
const playsResult = ref<PlaysImportResult | null>(null)
const playsErrorMessage = ref<string | null>(null)

async function onPlaysSubmit() {
  playsSubmitting.value = true
  playsErrorMessage.value = null

  try {
    playsResult.value = await plays.importPlays(playsUsername.value)
    plays.fetchPage(1)
  } catch {
    playsErrorMessage.value = t('importBgg.playsGenericError')
  } finally {
    playsSubmitting.value = false
  }
}

// In-app navigation never actually interrupts either import (the request
// keeps running regardless of which view is rendered, and the CSV side is
// wrapped in one DB transaction so it's all-or-nothing anyway) - the real
// risk is closing the tab or reloading mid-request, which genuinely aborts
// it. Warn only for that.
const isImporting = computed(() => phase.value === 'pending' || csvSubmitting.value || playsSubmitting.value)

function warnBeforeUnload(event: BeforeUnloadEvent) {
  if (isImporting.value) {
    event.preventDefault()
    event.returnValue = ''
  }
}

onMounted(() => {
  window.addEventListener('beforeunload', warnBeforeUnload)
})

onUnmounted(() => {
  window.removeEventListener('beforeunload', warnBeforeUnload)
})

onMounted(() => {
  const pendingId = localStorage.getItem(PENDING_IMPORT_KEY)

  if (pendingId) {
    phase.value = 'pending'
    poll(pendingId)
  }
})
</script>

<template>
  <div class="import-bgg">
    <div class="card">
      <h1>{{ $t('importBgg.title') }}</h1>

      <div class="method-tabs" role="tablist">
        <button
          type="button"
          role="tab"
          :aria-selected="method === 'username'"
          :class="['method-tab', { active: method === 'username' }]"
          @click="method = 'username'"
        >
          {{ $t('importBgg.tabUsername') }}
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="method === 'csv'"
          :class="['method-tab', { active: method === 'csv' }]"
          @click="method = 'csv'"
        >
          {{ $t('importBgg.tabCsv') }}
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="method === 'plays'"
          :class="['method-tab', { active: method === 'plays' }]"
          @click="method = 'plays'"
        >
          {{ $t('importBgg.tabPlays') }}
        </button>
      </div>

      <template v-if="method === 'username'">
        <form v-if="phase === 'idle' || phase === 'failed'" class="form" @submit.prevent="onSubmit">
          <p class="hint">{{ $t('importBgg.usernameHint') }}</p>

          <div>
            <label for="bgg_username">{{ $t('importBgg.username') }}</label>
            <input id="bgg_username" v-model="username" type="text" required :disabled="submitting" />
          </div>

          <p v-if="phase === 'failed'" role="alert" class="alert alert-error">
            {{ errorMessage ?? $t('importBgg.genericFailedError') }}
          </p>

          <button type="submit" class="btn btn-primary" :disabled="submitting">
            {{ $t('importBgg.submit') }}
          </button>
        </form>

        <div v-else-if="phase === 'pending'" role="status" class="status-block">
          <LoadingSpinner :size="32" />
          <p>
            {{ $t('importBgg.pending') }}
          </p>
          <p class="alert alert-info">{{ $t('importBgg.dontCloseTab') }}</p>
        </div>

        <div v-else-if="phase === 'completed'" role="status" class="status-block">
          <p>{{ $t('importBgg.completed', { count: importedCount }) }}</p>
          <RouterLink :to="{ name: 'dashboard' }" class="btn btn-primary">{{
            $t('importBgg.viewCollection')
          }}</RouterLink>
        </div>
      </template>

      <template v-else-if="method === 'csv'">
        <form v-if="!csvResult" class="form" @submit.prevent="onCsvSubmit">
          <p class="hint">{{ $t('importBgg.csvHint') }}</p>
          <p class="hint">{{ $t('importBgg.readOnlyNotice') }}</p>

          <div>
            <label for="csv_file">{{ $t('importBgg.csvFile') }}</label>
            <input
              id="csv_file"
              type="file"
              accept=".csv,text/csv"
              required
              :disabled="csvSubmitting"
              @change="onCsvFileChange"
            />
          </div>

          <p v-if="csvErrorMessage" role="alert" class="alert alert-error">
            {{ csvErrorMessage }}
          </p>

          <p v-if="csvSubmitting" class="alert alert-info csv-submitting">
            <LoadingSpinner :size="20" />
            {{ $t('importBgg.dontCloseTab') }}
          </p>

          <button type="submit" class="btn btn-primary" :disabled="csvSubmitting || !csvFile">
            {{ csvSubmitting ? $t('importBgg.csvSubmitting') : $t('importBgg.csvSubmit') }}
          </button>
        </form>

        <div v-else role="status" class="status-block">
          <p>
            {{
              csvResult.skipped_expansions_count > 0
                ? $t('importBgg.csvCompletedWithSkipped', {
                    count: csvResult.imported_count,
                    skipped: csvResult.skipped_expansions_count,
                  })
                : $t('importBgg.csvCompleted', { count: csvResult.imported_count })
            }}
          </p>

          <div v-if="csvResult.warnings.length" class="warnings">
            <p class="warnings-title">{{ $t('importBgg.csvWarningsTitle') }}</p>
            <ul>
              <li v-for="warning in csvResult.warnings" :key="warning">{{ warning }}</li>
            </ul>
          </div>

          <RouterLink :to="{ name: 'dashboard' }" class="btn btn-primary">{{
            $t('importBgg.viewCollection')
          }}</RouterLink>
        </div>
      </template>

      <template v-else>
        <form v-if="!playsResult" class="form" @submit.prevent="onPlaysSubmit">
          <p class="hint">{{ $t('importBgg.playsHint') }}</p>

          <div>
            <label for="plays_bgg_username">{{ $t('importBgg.username') }}</label>
            <input
              id="plays_bgg_username"
              v-model="playsUsername"
              type="text"
              required
              :disabled="playsSubmitting"
            />
          </div>

          <p v-if="playsErrorMessage" role="alert" class="alert alert-error">
            {{ playsErrorMessage }}
          </p>

          <p v-if="playsSubmitting" class="alert alert-info csv-submitting">
            <LoadingSpinner :size="20" />
            {{ $t('importBgg.dontCloseTab') }}
          </p>

          <button type="submit" class="btn btn-primary" :disabled="playsSubmitting || !playsUsername">
            {{ playsSubmitting ? $t('importBgg.playsSubmitting') : $t('importBgg.playsSubmit') }}
          </button>
        </form>

        <div v-else role="status" class="status-block">
          <p>{{ $t('importBgg.playsCompleted', { count: playsResult.imported_count }) }}</p>
          <RouterLink :to="{ name: 'plays' }" class="btn btn-primary">{{
            $t('importBgg.viewPlays')
          }}</RouterLink>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.import-bgg {
  max-width: 440px;
  margin: var(--space-8) auto 0;
}

h1 {
  margin-bottom: var(--space-4);
}

.method-tabs {
  display: flex;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
  border-bottom: 1px solid var(--color-border-strong);
}

.method-tab {
  padding: var(--space-2) var(--space-3);
  border: none;
  background: none;
  color: var(--color-text-muted);
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
}

.method-tab.active {
  color: var(--color-heading);
  border-bottom-color: var(--color-primary);
  font-weight: 500;
}

.hint {
  color: var(--color-text-muted);
  font-size: 0.9rem;
  margin-bottom: var(--space-2);
}

.status-block {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  align-items: center;
  text-align: center;
  color: var(--color-text-muted);
}

.csv-submitting {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.warnings {
  font-size: 0.85rem;
}

.warnings-title {
  font-weight: 500;
  margin-bottom: var(--space-1);
}

.warnings ul {
  margin: 0;
  padding-left: var(--space-4);
}
</style>
