<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

async function onSubmit() {
  error.value = null
  submitting.value = true

  try {
    await auth.login({ email: email.value, password: password.value })
    router.push({ name: 'dashboard' })
  } catch {
    error.value = 'Email o contraseña incorrectos.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth-form">
    <div class="card">
      <h1>Iniciar sesión</h1>

      <form class="form" @submit.prevent="onSubmit">
        <div>
          <label for="email">Email</label>
          <input id="email" v-model="email" type="email" required autocomplete="email" />
        </div>

        <div>
          <label for="password">Contraseña</label>
          <input
            id="password"
            v-model="password"
            type="password"
            required
            autocomplete="current-password"
          />
        </div>

        <p v-if="error" role="alert" class="alert alert-error">{{ error }}</p>

        <button type="submit" class="btn btn-primary" :disabled="submitting">
          {{ submitting ? 'Entrando…' : 'Entrar' }}
        </button>
      </form>
    </div>

    <p class="switch-link">
      ¿No tienes cuenta?
      <RouterLink :to="{ name: 'register' }">Regístrate</RouterLink>
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
