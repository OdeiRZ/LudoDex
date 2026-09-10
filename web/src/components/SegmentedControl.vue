<script setup lang="ts" generic="T extends string">
defineProps<{ modelValue: T; options: { value: T; label: string }[] }>()
defineEmits<{ 'update:modelValue': [value: T] }>()
</script>

<template>
  <div
    class="segmented-control"
    :style="{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }"
    role="group"
  >
    <button
      v-for="option in options"
      :key="option.value"
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
  display: grid;
  gap: var(--space-1);
  padding: var(--space-1);
  border-radius: var(--radius);
  background: var(--color-surface-hover);
}

.segmented-control-btn {
  padding: var(--space-2) var(--space-3);
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--color-text-muted);
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
}

.segmented-control-btn.active {
  background: var(--color-surface);
  color: var(--color-primary-hover);
  box-shadow: var(--shadow-card);
}
</style>
