<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isAxiosError } from 'axios'

const router = useRouter()
const auth = useAuthStore()

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
    await auth.register({
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    router.push({ name: 'dashboard' })
  } catch (err) {
    if (isAxiosError(err) && err.response?.status === 422) {
      errors.value = err.response.data.errors
    } else {
      errors.value = { general: ['Algo ha ido mal. Inténtalo de nuevo.'] }
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="auth-form">
    <div class="card">
      <h1>Crear cuenta</h1>

      <form class="form" @submit.prevent="onSubmit">
        <div>
          <label for="name">Nombre</label>
          <input id="name" v-model="name" type="text" required autocomplete="name" />
          <p v-for="message in errors.name" :key="message" role="alert" class="alert alert-error">
            {{ message }}
          </p>
        </div>

        <div>
          <label for="email">Email</label>
          <input id="email" v-model="email" type="email" required autocomplete="email" />
          <p v-for="message in errors.email" :key="message" role="alert" class="alert alert-error">
            {{ message }}
          </p>
        </div>

        <div>
          <label for="password">Contraseña</label>
          <input
            id="password"
            v-model="password"
            type="password"
            required
            autocomplete="new-password"
          />
        </div>

        <div>
          <label for="password_confirmation">Repite la contraseña</label>
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
          {{ submitting ? 'Creando cuenta…' : 'Crear cuenta' }}
        </button>
      </form>
    </div>

    <p class="switch-link">
      ¿Ya tienes cuenta?
      <RouterLink :to="{ name: 'login' }">Inicia sesión</RouterLink>
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
