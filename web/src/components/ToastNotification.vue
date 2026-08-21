<script setup lang="ts">
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
</script>

<template>
  <Transition name="toast">
    <p v-if="toast.message" role="status" class="toast alert alert-success">
      {{ toast.message }}
    </p>
  </Transition>
</template>

<style scoped>
.toast {
  position: fixed;
  left: 50%;
  bottom: var(--space-6);
  transform: translateX(-50%);
  z-index: 100;
  box-shadow: var(--shadow-card);
  text-align: center;
}

/* .alert-success's usual translucent tint (18%) assumes solid, opaque
content behind it - fine for an inline banner sitting on the app's own
background, but this toast floats over literally anything (game cover
photos, busy content), where that translucency made it barely legible.
A fixed solid color instead, independent of the light/dark theme's own
--color-success (which swaps between a light and a dark green, not
reliably paired with readable white text either way). */
.toast.alert-success {
  background: #15803d;
  color: #fff;
}

.toast-enter-active,
.toast-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateX(-50%) translateY(8px);
}
</style>
