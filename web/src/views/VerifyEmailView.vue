<script setup lang="ts">
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

// Set by EmailVerificationController::verify() in the API - the link the
// verification email points at lands here either way (valid or not), so
// this view never has to call the backend itself, just read the outcome.
const ok = route.query.ok === '1'
</script>

<template>
  <div class="auth-form">
    <div class="card">
      <template v-if="ok">
        <h1>{{ $t('auth.verifyEmail.successTitle') }}</h1>
        <p role="status" class="alert alert-success">{{ $t('auth.verifyEmail.successBody') }}</p>
      </template>
      <template v-else>
        <h1>{{ $t('auth.verifyEmail.failureTitle') }}</h1>
        <p role="alert" class="alert alert-error">{{ $t('auth.verifyEmail.failureBody') }}</p>
      </template>

      <RouterLink v-if="auth.isAuthenticated" :to="{ name: 'dashboard' }">
        {{ $t('auth.verifyEmail.goToDashboard') }}
      </RouterLink>
      <RouterLink v-else :to="{ name: 'login' }">
        {{ $t('auth.verifyEmail.goToLogin') }}
      </RouterLink>
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
