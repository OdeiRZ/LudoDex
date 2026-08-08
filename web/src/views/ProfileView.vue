<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useAuthStore } from '@/stores/auth'
import { useSlowRequestHint } from '@/composables/useSlowRequestHint'

const auth = useAuthStore()
const { isSlow: isProfileSlow, wrap: wrapProfile } = useSlowRequestHint()
const { isSlow: isPasswordSlow, wrap: wrapPassword } = useSlowRequestHint()

const profileForm = reactive({ name: '', email: '' })
const profileErrors = ref<Record<string, string[]>>({})
const profileSubmitting = ref(false)
const profileSaved = ref(false)

const passwordForm = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
})
const passwordErrors = ref<Record<string, string[]>>({})
const passwordSubmitting = ref(false)
const passwordSaved = ref(false)

onMounted(async () => {
  if (!auth.user) {
    await auth.fetchCurrentUser()
  }

  if (auth.user) {
    profileForm.name = auth.user.name
    profileForm.email = auth.user.email
  }
})

async function onSubmitProfile() {
  profileErrors.value = {}
  profileSaved.value = false
  profileSubmitting.value = true

  try {
    await wrapProfile(auth.updateProfile(profileForm))
    profileSaved.value = true
  } catch (err) {
    if (isAxiosError(err) && err.response?.status === 422) {
      profileErrors.value = err.response.data.errors
    } else {
      profileErrors.value = { general: ['No se han podido guardar los cambios.'] }
    }
  } finally {
    profileSubmitting.value = false
  }
}

async function onSubmitPassword() {
  passwordErrors.value = {}
  passwordSaved.value = false
  passwordSubmitting.value = true

  try {
    await wrapPassword(auth.updatePassword(passwordForm))
    passwordSaved.value = true
    passwordForm.current_password = ''
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
  } catch (err) {
    if (isAxiosError(err) && err.response?.status === 422) {
      passwordErrors.value = err.response.data.errors
    } else {
      passwordErrors.value = { general: ['No se ha podido cambiar la contraseña.'] }
    }
  } finally {
    passwordSubmitting.value = false
  }
}
</script>

<template>
  <div class="profile">
    <h1>Mi perfil</h1>

    <section class="card">
      <h2>Datos personales</h2>

      <form class="form" @submit.prevent="onSubmitProfile">
        <div>
          <label for="name">Nombre</label>
          <input id="name" v-model="profileForm.name" type="text" required autocomplete="name" />
          <p
            v-for="message in profileErrors.name"
            :key="message"
            role="alert"
            class="alert alert-error"
          >
            {{ message }}
          </p>
        </div>

        <div>
          <label for="email">Email</label>
          <input id="email" v-model="profileForm.email" type="email" required autocomplete="email" />
          <p
            v-for="message in profileErrors.email"
            :key="message"
            role="alert"
            class="alert alert-error"
          >
            {{ message }}
          </p>
        </div>

        <p
          v-for="message in profileErrors.general"
          :key="message"
          role="alert"
          class="alert alert-error"
        >
          {{ message }}
        </p>
        <p v-if="profileSaved" role="status" class="alert alert-success">Cambios guardados.</p>

        <button type="submit" class="btn btn-primary" :disabled="profileSubmitting">
          {{ profileSubmitting ? 'Guardando…' : 'Guardar cambios' }}
        </button>

        <p v-if="isProfileSlow" class="slow-request-hint">
          Puede tardar unos segundos si el servidor estaba inactivo.
        </p>
      </form>
    </section>

    <section class="card">
      <h2>Cambiar contraseña</h2>

      <form class="form" @submit.prevent="onSubmitPassword">
        <div>
          <label for="current_password">Contraseña actual</label>
          <input
            id="current_password"
            v-model="passwordForm.current_password"
            type="password"
            required
            autocomplete="current-password"
          />
          <p
            v-for="message in passwordErrors.current_password"
            :key="message"
            role="alert"
            class="alert alert-error"
          >
            {{ message }}
          </p>
        </div>

        <div>
          <label for="new_password">Nueva contraseña</label>
          <input
            id="new_password"
            v-model="passwordForm.password"
            type="password"
            required
            autocomplete="new-password"
          />
        </div>

        <div>
          <label for="new_password_confirmation">Repite la nueva contraseña</label>
          <input
            id="new_password_confirmation"
            v-model="passwordForm.password_confirmation"
            type="password"
            required
            autocomplete="new-password"
          />
          <p
            v-for="message in passwordErrors.password"
            :key="message"
            role="alert"
            class="alert alert-error"
          >
            {{ message }}
          </p>
        </div>

        <p
          v-for="message in passwordErrors.general"
          :key="message"
          role="alert"
          class="alert alert-error"
        >
          {{ message }}
        </p>
        <p v-if="passwordSaved" role="status" class="alert alert-success">
          Contraseña actualizada.
        </p>

        <button type="submit" class="btn btn-primary" :disabled="passwordSubmitting">
          {{ passwordSubmitting ? 'Guardando…' : 'Cambiar contraseña' }}
        </button>

        <p v-if="isPasswordSlow" class="slow-request-hint">
          Puede tardar unos segundos si el servidor estaba inactivo.
        </p>
      </form>
    </section>
  </div>
</template>

<style scoped>
.profile {
  max-width: 480px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
}

h1 {
  margin-bottom: var(--space-2);
}

h2 {
  margin-bottom: var(--space-4);
}
</style>
