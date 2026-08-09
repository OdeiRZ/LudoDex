<script setup lang="ts">
import { ref } from 'vue'

defineProps<{ id: string }>()

const model = defineModel<string>({ required: true })

defineOptions({ inheritAttrs: false })

const visible = ref(false)
</script>

<template>
  <div class="password-field">
    <input :id="id" v-model="model" :type="visible ? 'text' : 'password'" v-bind="$attrs" />
    <button
      type="button"
      class="toggle-visibility"
      :aria-label="visible ? $t('common.hidePassword') : $t('common.showPassword')"
      :title="visible ? $t('common.hidePassword') : $t('common.showPassword')"
      @click="visible = !visible"
    >
      <svg v-if="visible" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M3 3l18 18M10.58 10.58a2 2 0 0 0 2.83 2.83M9.36 5.52A9.77 9.77 0 0 1 12 5c5 0 9 4 10 7a12.5 12.5 0 0 1-2.16 3.19M6.6 6.6C4.4 8 2.9 10 2 12c1 3 5 7 10 7a9.7 9.7 0 0 0 3.4-.6"
        />
      </svg>
      <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7Z" />
        <circle cx="12" cy="12" r="3" />
      </svg>
    </button>
  </div>
</template>

<style scoped>
.password-field {
  position: relative;
}

.password-field input {
  padding-right: 2.5rem;
}

.toggle-visibility {
  position: absolute;
  top: 50%;
  right: 0.5rem;
  transform: translateY(-50%);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  padding: 0;
  border: none;
  background: none;
  color: var(--color-text-muted);
}

.toggle-visibility:hover {
  color: var(--color-text);
}

.toggle-visibility svg {
  width: 18px;
  height: 18px;
}
</style>
