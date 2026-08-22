<script setup lang="ts">
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import PasswordInput from '@/components/PasswordInput.vue'
import { useSlowRequestHint } from '@/composables/useSlowRequestHint'

const route = useRoute()
const auth = useAuthStore()
const { t } = useI18n()
const { isSlow, wrap } = useSlowRequestHint()

// Both come from the link the password-reset email points at (see the
// API's AppServiceProvider) - a query string rather than route params
// since Laravel's own Password::reset() needs both to look up the token.
const token = typeof route.query.token === 'string' ? route.query.token : ''
const email = typeof route.query.email === 'string' ? route.query.email : ''

const password = ref('')
const passwordConfirmation = ref('')
const errors = ref<Record<string, string[]>>({})
const successMessage = ref<string | null>(null)
const submitting = ref(false)

async function onSubmit() {
  errors.value = {}
  submitting.value = true

  try {
    successMessage.value = await wrap(
      auth.resetPassword({
        token,
        email,
        password: password.value,
        password_confirmation: passwordConfirmation.value,
      }),
    )
  } catch (err) {
    if (isAxiosError(err) && err.response?.status === 422) {
      errors.value = err.response.data.errors
    } else {
      errors.value = { general: [t('auth.resetPassword.genericError')] }
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth-form">
    <div class="card">
      <h1>{{ $t('auth.resetPassword.title') }}</h1>

      <p v-if="successMessage" role="status" class="alert alert-success">
        {{ successMessage }}
        <RouterLink :to="{ name: 'login' }">{{ $t('auth.resetPassword.goToLogin') }}</RouterLink>
      </p>

      <form v-else class="form" @submit.prevent="onSubmit">
        <div>
          <label for="password">{{ $t('auth.resetPassword.password') }}</label>
          <PasswordInput id="password" v-model="password" required autocomplete="new-password" />
        </div>

        <div>
          <label for="password_confirmation">{{ $t('auth.resetPassword.passwordConfirmation') }}</label>
          <PasswordInput
            id="password_confirmation"
            v-model="passwordConfirmation"
            required
            autocomplete="new-password"
          />
          <p
            v-for="message in errors.password"
            :key="message"
            role="alert"
            class="alert alert-error"
          >
            {{ message }}
          </p>
        </div>

        <p v-for="message in errors.email" :key="message" role="alert" class="alert alert-error">
          {{ message }}
        </p>
        <p v-for="message in errors.general" :key="message" role="alert" class="alert alert-error">
          {{ message }}
        </p>

        <button type="submit" class="btn btn-primary" :disabled="submitting">
          {{ submitting ? $t('auth.resetPassword.submitting') : $t('auth.resetPassword.submit') }}
        </button>

        <p v-if="isSlow" class="slow-request-hint">
          <LoadingSpinner :size="24" />
          {{ $t('common.coldStartHint') }}
        </p>
      </form>
    </div>
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
</style>
