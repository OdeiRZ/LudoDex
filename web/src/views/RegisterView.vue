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
    <h1>Crear cuenta</h1>

    <form @submit.prevent="onSubmit">
      <label for="name">Nombre</label>
      <input id="name" v-model="name" type="text" required autocomplete="name" />
      <p v-for="message in errors.name" :key="message" role="alert" class="error">
        {{ message }}
      </p>

      <label for="email">Email</label>
      <input id="email" v-model="email" type="email" required autocomplete="email" />
      <p v-for="message in errors.email" :key="message" role="alert" class="error">
        {{ message }}
      </p>

      <label for="password">Contraseña</label>
      <input
        id="password"
        v-model="password"
        type="password"
        required
        autocomplete="new-password"
      />

      <label for="password_confirmation">Repite la contraseña</label>
      <input
        id="password_confirmation"
        v-model="passwordConfirmation"
        type="password"
        required
        autocomplete="new-password"
      />
      <p v-for="message in errors.password" :key="message" role="alert" class="error">
        {{ message }}
      </p>

      <p v-for="message in errors.general" :key="message" role="alert" class="error">
        {{ message }}
      </p>

      <button type="submit" :disabled="submitting">
        {{ submitting ? 'Creando cuenta…' : 'Crear cuenta' }}
      </button>
    </form>

    <p>
      ¿Ya tienes cuenta?
      <RouterLink :to="{ name: 'login' }">Inicia sesión</RouterLink>
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
  margin: 0;
}
</style>
