<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { isAxiosError } from 'axios'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import { useSlowRequestHint } from '@/composables/useSlowRequestHint'

const router = useRouter()
const auth = useAuthStore()
const { t } = useI18n()
const { isSlow, wrap } = useSlowRequestHint()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const errors = ref<Record<string, string[]>>({})
const submitting = ref(false)

async function onSubmit() {
  errors.value = {}
  submitting.value = true

  try {
    await wrap(
      auth.register({
        name: name.value,
        email: email.value,
        password: password.value,
        password_confirmation: passwordConfirmation.value,
      }),
    )
    router.push({ name: 'dashboard' })
  } catch (err) {
    if (isAxiosError(err) && err.response?.status === 422) {
      errors.value = err.response.data.errors
    } else {
      errors.value = { general: [t('auth.register.genericError')] }
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth-form">
    <div class="card">
      <h1>{{ $t('auth.register.title') }}</h1>

      <form class="form" @submit.prevent="onSubmit">
        <div>
          <label for="name">{{ $t('auth.register.name') }}</label>
          <input id="name" v-model="name" type="text" required autocomplete="name" />
          <p v-for="message in errors.name" :key="message" role="alert" class="alert alert-error">
            {{ message }}
          </p>
        </div>

        <div>
          <label for="email">{{ $t('auth.register.email') }}</label>
          <input id="email" v-model="email" type="email" required autocomplete="email" />
          <p v-for="message in errors.email" :key="message" role="alert" class="alert alert-error">
            {{ message }}
          </p>
        </div>

        <div>
          <label for="password">{{ $t('auth.register.password') }}</label>
          <input
            id="password"
            v-model="password"
            type="password"
            required
            autocomplete="new-password"
          />
        </div>

        <div>
          <label for="password_confirmation">{{ $t('auth.register.passwordConfirmation') }}</label>
          <input
            id="password_confirmation"
            v-model="passwordConfirmation"
            type="password"
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

        <p v-for="message in errors.general" :key="message" role="alert" class="alert alert-error">
          {{ message }}
        </p>

        <button type="submit" class="btn btn-primary" :disabled="submitting">
          {{ submitting ? $t('auth.register.submitting') : $t('auth.register.submit') }}
        </button>

        <p v-if="isSlow" class="slow-request-hint">
          <LoadingSpinner :size="16" />
          {{ $t('common.coldStartHint') }}
        </p>
      </form>
    </div>

    <p class="switch-link">
      {{ $t('auth.register.hasAccount') }}
      <RouterLink :to="{ name: 'login' }">{{ $t('auth.register.loginLink') }}</RouterLink>
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
</style>
