<script setup lang="ts">
import { ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { useSlowRequestHint } from '@/composables/useSlowRequestHint'

const auth = useAuthStore()
const { t } = useI18n()
const { isSlow, wrap } = useSlowRequestHint()

const email = ref('')
const errors = ref<Record<string, string[]>>({})
const successMessage = ref<string | null>(null)
const submitting = ref(false)

async function onSubmit() {
  errors.value = {}
  submitting.value = true

  try {
    successMessage.value = await wrap(auth.forgotPassword(email.value))
  } catch (err) {
    if (isAxiosError(err) && err.response?.status === 422) {
      errors.value = err.response.data.errors
    } else {
      errors.value = { general: [t('auth.forgotPassword.genericError')] }
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth-form">
    <div class="card">
      <h1>{{ $t('auth.forgotPassword.title') }}</h1>

      <p v-if="successMessage" role="status" class="alert alert-success">
        {{ successMessage }}
      </p>

      <form v-else class="form" @submit.prevent="onSubmit">
        <p class="instructions">{{ $t('auth.forgotPassword.instructions') }}</p>

        <div>
          <label for="email">{{ $t('auth.forgotPassword.email') }}</label>
          <input id="email" v-model="email" type="email" required autocomplete="email" />
          <p v-for="message in errors.email" :key="message" role="alert" class="alert alert-error">
            {{ message }}
          </p>
        </div>

        <p v-for="message in errors.general" :key="message" role="alert" class="alert alert-error">
          {{ message }}
        </p>

        <button type="submit" class="btn btn-primary" :disabled="submitting">
          {{ submitting ? $t('auth.forgotPassword.submitting') : $t('auth.forgotPassword.submit') }}
        </button>

        <p v-if="isSlow" class="slow-request-hint">
          <LoadingSpinner :size="16" />
          {{ $t('common.coldStartHint') }}
        </p>
      </form>
    </div>

    <p class="switch-link">
      <RouterLink :to="{ name: 'login' }">{{ $t('auth.forgotPassword.backToLogin') }}</RouterLink>
    </p>
  </div>
</template>

<style scoped>
.auth-form {
  max-width: 380px;
  margin: var(--space-8) auto 0;
}

h1 {
  margin-bottom: var(--space-4);
}

.instructions {
  color: var(--color-text-muted);
  font-size: 0.9rem;
  margin-bottom: var(--space-4);
}

.switch-link {
  text-align: center;
  margin-top: var(--space-4);
  color: var(--color-text-muted);
  font-size: 0.9rem;
}
</style>
