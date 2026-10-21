<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { getLocale } from '@/i18n'
import { useToastStore } from '@/stores/toast'
import {
  DEFAULT_EXPIRY_DAYS,
  EXPIRY_OPTIONS,
  publicUrl,
  useShareLinkStore,
} from '@/stores/shareLink'

const { t } = useI18n()
const store = useShareLinkStore()
const toast = useToastStore()

// La caducidad que se aplicará al crear o regenerar; 7 días por defecto.
const expiryDays = ref<number | null>(DEFAULT_EXPIRY_DAYS)
const busy = ref(false)
const confirmingStop = ref(false)

const url = computed(() => (store.link ? publicUrl(store.link.token) : ''))

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString(getLocale(), {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

function optionLabel(days: number | null): string {
  return days === null ? t('share.expiryNever') : t('share.expiryDays', { days }, days)
}

async function run(action: () => Promise<void>, successMessage?: string) {
  busy.value = true
  try {
    await action()
    if (successMessage) toast.show(successMessage)
  } catch {
    toast.show(t('share.error'), 'error')
  } finally {
    busy.value = false
  }
}

const onCreate = () => run(() => store.create(expiryDays.value), t('share.created'))

async function onCopy() {
  try {
    await navigator.clipboard.writeText(url.value)
    toast.show(t('share.copied'))
  } catch {
    // Sin permiso de portapapeles: el campo es de solo lectura y se puede seleccionar.
    toast.show(t('share.copyFailed'), 'error')
  }
}

function onStop() {
  if (!confirmingStop.value) {
    confirmingStop.value = true
    return
  }
  confirmingStop.value = false
  run(() => store.remove(), t('share.stopped'))
}

onMounted(() => {
  if (!store.loaded) store.fetch()
})
</script>

<template>
  <section class="card share" data-testid="share-card">
    <h2>{{ $t('share.title') }}</h2>
    <p class="hint">{{ $t('share.hint') }}</p>

    <p v-if="store.error" class="error" role="alert">{{ $t('share.error') }}</p>

    <template v-else-if="store.loaded">
      <div v-if="store.link" class="current">
        <p v-if="store.link.expired" class="expired" role="status">{{ $t('share.expired') }}</p>

        <div class="url-row">
          <input
            :value="url"
            readonly
            :aria-label="$t('share.linkLabel')"
            @focus="($event.target as HTMLInputElement).select()"
          />
          <button
            type="button"
            class="btn btn-primary"
            :disabled="busy || store.link.expired"
            @click="onCopy"
          >
            {{ $t('share.copy') }}
          </button>
        </div>

        <p class="meta">
          <span v-if="store.link.expires_at">
            {{ $t('share.expiresOn', { date: formatDate(store.link.expires_at) }) }}
          </span>
          <span v-else>{{ $t('share.neverExpires') }}</span>
          ·
          {{ $t('share.views', { count: store.link.views_count }, store.link.views_count) }}
        </p>
      </div>

      <div class="actions">
        <label class="expiry">
          <span>{{ $t('share.expiryLabel') }}</span>
          <select v-model="expiryDays" :disabled="busy">
            <option v-for="days in EXPIRY_OPTIONS" :key="String(days)" :value="days">
              {{ optionLabel(days) }}
            </option>
          </select>
        </label>

        <button type="button" class="btn btn-primary" :disabled="busy" @click="onCreate">
          {{ store.link ? $t('share.regenerate') : $t('share.create') }}
        </button>
        <button
          v-if="store.link"
          type="button"
          class="btn btn-danger"
          :disabled="busy"
          @click="onStop"
        >
          {{ confirmingStop ? $t('share.stopConfirm') : $t('share.stop') }}
        </button>
      </div>
      <p v-if="store.link" class="hint">{{ $t('share.regenerateHint') }}</p>
    </template>
  </section>
</template>

<style scoped>
.hint {
  color: var(--color-text-muted);
  font-size: 0.9rem;
  margin-bottom: var(--space-3);
}

.error,
.expired {
  color: var(--color-danger, #c0392b);
  margin-bottom: var(--space-3);
}

.url-row {
  display: flex;
  gap: var(--space-2);
  margin-bottom: var(--space-2);
}

.url-row input {
  flex: 1;
  min-width: 0;
}

.meta {
  color: var(--color-text-muted);
  font-size: 0.9rem;
  margin-bottom: var(--space-3);
}

.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: var(--space-2);
}

.expiry {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  font-size: 0.85rem;
}
</style>
