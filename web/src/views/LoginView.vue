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
    <h1>Iniciar sesión</h1>

    <form @submit.prevent="onSubmit">
      <label for="email">Email</label>
      <input id="email" v-model="email" type="email" required autocomplete="email" />

      <label for="password">Contraseña</label>
      <input
        id="password"
        v-model="password"
        type="password"
        required
        autocomplete="current-password"
      />

      <p v-if="error" role="alert" class="error">{{ error }}</p>

      <button type="submit" :disabled="submitting">
        {{ submitting ? 'Entrando…' : 'Entrar' }}
      </button>
    </form>

    <p>
      ¿No tienes cuenta?
      <RouterLink :to="{ name: 'register' }">Regístrate</RouterLink>
    </p>
  </div>
</template>

<style scoped>
.auth-form {
  max-width: 360px;
  margin: 4rem auto;
}

form {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.error {
  color: #d33;
}
</style>
