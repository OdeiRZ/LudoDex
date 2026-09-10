<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSlowRequestHint } from '@/composables/useSlowRequestHint'
import { getLocale, setLocale, type Locale } from '@/i18n'
import UserAvatar from '@/components/UserAvatar.vue'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import PasswordInput from '@/components/PasswordInput.vue'
import SegmentedControl from '@/components/SegmentedControl.vue'

const auth = useAuthStore()
const { t } = useI18n()
const { isSlow: isProfileSlow, wrap: wrapProfile } = useSlowRequestHint()
const { isSlow: isPasswordSlow, wrap: wrapPassword } = useSlowRequestHint()

// Picking a language directly (both options always visible) instead of a
// single button that cycles through them - asked for directly, same
// SegmentedControl pattern already used in PequeDex. Language names shown
// in themselves (Español/English), not translated - conventional for a
// language switcher regardless of which one the reader currently
// understands.
const localeOptions = computed<{ value: Locale; label: string }[]>(() => [
  { value: 'es', label: 'Español' },
  { value: 'en', label: 'English' },
])
const locale = computed(() => getLocale())

function onSelectLocale(value: Locale) {
  setLocale(value)
}

const profileForm = reactive({
  name: '',
  email: '',
  bgg_username: '' as string | null,
  discoverable: false,
  share_activity: true,
})
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
    profileForm.discoverable = auth.user.discoverable
    profileForm.share_activity = auth.user.share_activity
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
        <UserAvatar
          :name="profileForm.name || '?'"
          :avatar-url="auth.user?.avatar_url"
          :size="64"
        />
        <p class="avatar-hint">
          {{ $t('profile.avatarHint') }}
        </p>
      </div>

      <!-- Not part of the form below - applies instantly on click, same as
      it always has, unlike name/email/etc which wait for "Guardar". Moved
      here from the header nav (freed up room there - see App.vue's own
      comment) - login/register have no account yet to hold a preference,
      so they fall back to the browser's own language now instead (see
      i18n/index.ts), same pattern already used in PequeDex. -->
      <div class="language-row">
        <span class="language-label">{{ $t('profile.language') }}</span>
        <SegmentedControl
          :model-value="locale"
          :options="localeOptions"
          @update:model-value="onSelectLocale"
        />
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
          <input
            id="email"
            v-model="profileForm.email"
            type="email"
            required
            autocomplete="email"
          />
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

        <div>
          <label class="checkbox-label" for="discoverable">
            <input id="discoverable" v-model="profileForm.discoverable" type="checkbox" />
            {{ $t('profile.discoverable') }}
          </label>
          <p class="discoverable-hint">{{ $t('profile.discoverableHint') }}</p>
        </div>

        <div>
          <label class="checkbox-label" for="share_activity">
            <input id="share_activity" v-model="profileForm.share_activity" type="checkbox" />
            {{ $t('profile.shareActivity') }}
          </label>
          <p class="discoverable-hint">{{ $t('profile.shareActivityHint') }}</p>
        </div>

        <p
          v-for="message in profileErrors.general"
          :key="message"
          role="alert"
          class="alert alert-error"
        >
          {{ message }}
        </p>
        <p v-if="profileSaved" role="status" class="alert alert-success">
          {{ $t('profile.saved') }}
        </p>

        <button type="submit" class="btn btn-primary" :disabled="profileSubmitting">
          {{ profileSubmitting ? $t('common.saving') : $t('profile.save') }}
        </button>

        <p v-if="isProfileSlow" class="slow-request-hint">
          <LoadingSpinner :size="24" />
          {{ $t('common.coldStartHint') }}
        </p>
      </form>
    </section>

    <!-- Moved here from the primary nav (freed up room there, same reasoning
    as the language toggle above) - used mostly once, at the start, and
    occasionally after. The manual "add a game" form is a separate route
    entirely, linked from Dashboard/Picker themselves, not from here or the
    old nav item - unaffected by this move. -->
    <section class="card">
      <h2>{{ $t('profile.importBggTitle') }}</h2>
      <p class="import-bgg-hint">{{ $t('profile.importBggHint') }}</p>
      <RouterLink :to="{ name: 'import-bgg' }" class="btn btn-primary">
        {{ $t('nav.importBgg') }}
      </RouterLink>
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
          <PasswordInput
            id="new_password"
            v-model="passwordForm.password"
            required
            autocomplete="new-password"
          />
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
          <LoadingSpinner :size="24" />
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

/* Label above the control, not side-by-side - a 2-button grid reads
better stacked under its own label than squeezed next to it, same
layout PequeDex already uses for this. */
.language-row {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
}

.language-label {
  font-weight: 500;
}

.import-bgg-hint {
  margin-bottom: var(--space-4);
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.checkbox-label input {
  width: auto;
}

.discoverable-hint {
  margin-top: var(--space-1);
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

h2 {
  margin-bottom: var(--space-4);
}
</style>
