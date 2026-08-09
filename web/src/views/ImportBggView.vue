<script setup lang="ts">
import { onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useGamesStore, type BggImportStatus } from '@/stores/games'
import LoadingSpinner from '@/components/LoadingSpinner.vue'

const games = useGamesStore()
const { t } = useI18n()

const username = ref('')
const phase = ref<'idle' | 'pending' | 'completed' | 'failed'>('idle')
const importedCount = ref<number | null>(null)
const errorMessage = ref<string | null>(null)
const submitting = ref(false)

const POLL_INTERVAL_MS = 3000
let pollTimer: ReturnType<typeof setTimeout> | undefined

function handleResult(result: BggImportStatus) {
  phase.value = result.status

  if (result.status === 'pending') {
    pollTimer = setTimeout(() => poll(result.id), POLL_INTERVAL_MS)
    return
  }

  importedCount.value = result.imported_count
  errorMessage.value = result.error_message

  if (result.status === 'completed') {
    games.fetchAll()
  }
}

async function poll(id: string) {
  const result = await games.pollBggImport(id)
  handleResult(result)
}

async function onSubmit() {
  submitting.value = true
  phase.value = 'pending'
  errorMessage.value = null

  try {
    const result = await games.startBggImport(username.value)
    handleResult(result)
  } catch {
    phase.value = 'failed'
    errorMessage.value = t('importBgg.genericStartError')
  } finally {
    submitting.value = false
  }
}

onUnmounted(() => {
  if (pollTimer) {
    clearTimeout(pollTimer)
  }
})
</script>

<template>
  <div class="import-bgg">
    <div class="card">
      <h1>{{ $t('importBgg.title') }}</h1>

      <form v-if="phase === 'idle' || phase === 'failed'" class="form" @submit.prevent="onSubmit">
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
      </div>

      <div v-else-if="phase === 'completed'" role="status" class="status-block">
        <p>{{ $t('importBgg.completed', { count: importedCount }) }}</p>
        <RouterLink :to="{ name: 'dashboard' }" class="btn btn-primary">{{
          $t('importBgg.viewCollection')
        }}</RouterLink>
      </div>
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

.status-block {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  align-items: flex-start;
  color: var(--color-text-muted);
}
</style>
