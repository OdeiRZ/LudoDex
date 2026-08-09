<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import PasswordInput from '@/components/PasswordInput.vue'
import { useSlowRequestHint } from '@/composables/useSlowRequestHint'

const router = useRouter()
const auth = useAuthStore()
const { t } = useI18n()
const { isSlow, wrap } = useSlowRequestHint()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

async function onSubmit() {
  error.value = null
  submitting.value = true

  try {
    await wrap(auth.login({ email: email.value, password: password.value }))
    router.push({ name: 'dashboard' })
  } catch {
    error.value = t('auth.login.error')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth-form">
    <div class="card">
      <h1>{{ $t('auth.login.title') }}</h1>

      <form class="form" @submit.prevent="onSubmit">
        <div>
          <label for="email">{{ $t('auth.login.email') }}</label>
          <input id="email" v-model="email" type="email" required autocomplete="email" />
        </div>

        <div>
          <label for="password">{{ $t('auth.login.password') }}</label>
          <PasswordInput id="password" v-model="password" required autocomplete="current-password" />
          <RouterLink :to="{ name: 'forgot-password' }" class="forgot-link">{{
            $t('auth.login.forgotPasswordLink')
          }}</RouterLink>
        </div>

        <p v-if="error" role="alert" class="alert alert-error">{{ error }}</p>

        <button type="submit" class="btn btn-primary" :disabled="submitting">
          {{ submitting ? $t('auth.login.submitting') : $t('auth.login.submit') }}
        </button>

        <p v-if="isSlow" class="slow-request-hint">
          <LoadingSpinner :size="16" />
          {{ $t('common.coldStartHint') }}
        </p>
      </form>
    </div>

    <p class="switch-link">
      {{ $t('auth.login.noAccount') }}
      <RouterLink :to="{ name: 'register' }">{{ $t('auth.login.registerLink') }}</RouterLink>
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

.switch-link {
  text-align: center;
  margin-top: var(--space-4);
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.forgot-link {
  display: inline-block;
  margin-top: var(--space-1);
  font-size: 0.85rem;
}
</style>
