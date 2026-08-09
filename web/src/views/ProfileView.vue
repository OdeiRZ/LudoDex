<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSlowRequestHint } from '@/composables/useSlowRequestHint'
import UserAvatar from '@/components/UserAvatar.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import PasswordInput from '@/components/PasswordInput.vue'

const auth = useAuthStore()
const { t } = useI18n()
const { isSlow: isProfileSlow, wrap: wrapProfile } = useSlowRequestHint()
const { isSlow: isPasswordSlow, wrap: wrapPassword } = useSlowRequestHint()

const profileForm = reactive({ name: '', email: '', bgg_username: '' as string | null })
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
    profileForm.bgg_username = auth.user.bgg_username
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
      profileErrors.value = { general: [t('profile.genericError')] }
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
      passwordErrors.value = { general: [t('profile.passwordGenericError')] }
    }
  } finally {
    passwordSubmitting.value = false
  }
}
</script>

<template>
  <div class="profile">
    <h1>{{ $t('profile.title') }}</h1>

    <section class="card">
      <h2>{{ $t('profile.personalData') }}</h2>

      <div class="avatar-preview">
        <UserAvatar :name="profileForm.name || '?'" :avatar-url="auth.user?.avatar_url" :size="64" />
        <p class="avatar-hint">
          {{ $t('profile.avatarHint') }}
        </p>
      </div>

      <form class="form" @submit.prevent="onSubmitProfile">
        <div>
          <label for="name">{{ $t('profile.name') }}</label>
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
          <label for="email">{{ $t('profile.email') }}</label>
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

        <div>
          <label for="bgg_username">{{ $t('profile.bggUsername') }}</label>
          <input id="bgg_username" v-model="profileForm.bgg_username" type="text" />
          <p
            v-for="message in profileErrors.bgg_username"
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
        <p v-if="profileSaved" role="status" class="alert alert-success">{{ $t('profile.saved') }}</p>

        <button type="submit" class="btn btn-primary" :disabled="profileSubmitting">
          {{ profileSubmitting ? $t('common.saving') : $t('profile.save') }}
        </button>

        <p v-if="isProfileSlow" class="slow-request-hint">
          <LoadingSpinner :size="16" />
          {{ $t('common.coldStartHint') }}
        </p>
      </form>
    </section>

    <section class="card">
      <h2>{{ $t('profile.changePassword') }}</h2>

      <form class="form" @submit.prevent="onSubmitPassword">
        <div>
          <label for="current_password">{{ $t('profile.currentPassword') }}</label>
          <PasswordInput
            id="current_password"
            v-model="passwordForm.current_password"
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
          <label for="new_password">{{ $t('profile.newPassword') }}</label>
          <PasswordInput id="new_password" v-model="passwordForm.password" required autocomplete="new-password" />
        </div>

        <div>
          <label for="new_password_confirmation">{{ $t('profile.newPasswordConfirmation') }}</label>
          <PasswordInput
            id="new_password_confirmation"
            v-model="passwordForm.password_confirmation"
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
          {{ $t('profile.passwordSaved') }}
        </p>

        <button type="submit" class="btn btn-primary" :disabled="passwordSubmitting">
          {{ passwordSubmitting ? $t('common.saving') : $t('profile.changePassword') }}
        </button>

        <p v-if="isPasswordSlow" class="slow-request-hint">
          <LoadingSpinner :size="16" />
          {{ $t('common.coldStartHint') }}
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

.avatar-preview {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  margin-bottom: var(--space-4);
}

.avatar-hint {
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

h2 {
  margin-bottom: var(--space-4);
}
</style>
