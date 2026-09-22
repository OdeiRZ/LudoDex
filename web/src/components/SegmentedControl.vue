<script setup lang="ts" generic="T extends string">
import { nextTick, onMounted, ref, watch } from 'vue'

const props = defineProps<{ modelValue: T; options: { value: T; label: string }[] }>()
defineEmits<{ 'update:modelValue': [value: T] }>()

// Measured off the actual active <button> (offsetLeft/offsetWidth, both
// relative to .segmented-control itself - the nearest positioned
// ancestor) rather than computed from the option count alone, since the
// grid's own `gap` between columns isn't a plain percentage a CSS-only
// calc could account for. Re-measures whenever the active option or the
// option list itself changes (a language switcher's options are fixed,
// but this component is generic - a caller with a dynamic option set
// shouldn't need its own re-measure logic).
const buttonRefs = ref<(HTMLButtonElement | null)[]>([])
const indicatorStyle = ref<{ width: string; transform: string }>({
  width: '0px',
  transform: 'translateX(0)',
})

async function updateIndicator() {
  await nextTick()

  const index = props.options.findIndex((option) => option.value === props.modelValue)
  const button = buttonRefs.value[index]
  if (!button) return

  indicatorStyle.value = {
    width: `${button.offsetWidth}px`,
    transform: `translateX(${button.offsetLeft}px)`,
  }
}

onMounted(updateIndicator)
watch(() => props.modelValue, updateIndicator)
watch(() => props.options, updateIndicator)
</script>

<template>
  <div
    class="segmented-control"
    :style="{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }"
    role="group"
  >
    <span class="segmented-control-indicator" :style="indicatorStyle" aria-hidden="true"></span>
    <button
      v-for="(option, index) in options"
      :key="option.value"
      :ref="(el) => (buttonRefs[index] = el as HTMLButtonElement | null)"
      type="button"
      class="segmented-control-btn"
      :class="{ active: modelValue === option.value }"
      :aria-pressed="modelValue === option.value"
      @click="$emit('update:modelValue', option.value)"
    >
      {{ option.label }}
    </button>
  </div>
</template>

<style scoped>
.segmented-control {
  position: relative;
  display: grid;
  gap: var(--space-1);
  padding: var(--space-1);
  border-radius: var(--radius);
  background: var(--color-surface-hover);
}

/* One pill that travels to whichever option is active, instead of each
   button flatly toggling its own background/shadow with nothing in
   between - a real slide reads as a single mechanical control, not N
   buttons independently guessing they're the chosen one. */
.segmented-control-indicator {
  position: absolute;
  top: var(--space-1);
  bottom: var(--space-1);
  left: 0;
  border-radius: var(--radius-sm);
  background: var(--color-surface);
  box-shadow: var(--shadow-card);
  transition: transform 0.28s cubic-bezier(0.65, 0, 0.35, 1);
}

.segmented-control-btn {
  position: relative;
  z-index: 1;
  padding: var(--space-2) var(--space-3);
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--color-text-muted);
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  transition: color 0.2s ease;
}

.segmented-control-btn.active {
  color: var(--color-primary-hover);
}

@media (prefers-reduced-motion: reduce) {
  .segmented-control-indicator {
    transition: none;
  }
}
</style>
