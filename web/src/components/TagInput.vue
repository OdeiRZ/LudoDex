<script setup lang="ts">
import { ref } from 'vue'

const props = defineProps<{
  modelValue: string[]
  suggestions: string[]
  label: string
  listId: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string[]]
}>()

const draft = ref('')

function addTag() {
  const value = draft.value.trim()

  if (value && !props.modelValue.includes(value)) {
    emit('update:modelValue', [...props.modelValue, value])
  }

  draft.value = ''
}

function removeTag(tag: string) {
  emit(
    'update:modelValue',
    props.modelValue.filter((existing) => existing !== tag),
  )
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' || event.key === ',') {
    event.preventDefault()
    addTag()
  }
}
</script>

<template>
  <div class="tag-input">
    <label :for="listId">{{ label }}</label>

    <ul v-if="modelValue.length" class="tags">
      <li v-for="tag in modelValue" :key="tag">
        {{ tag }}
        <button type="button" :aria-label="`Quitar ${tag}`" @click="removeTag(tag)">×</button>
      </li>
    </ul>

    <input
      :id="listId"
      v-model="draft"
      type="text"
      :list="`${listId}-suggestions`"
      placeholder="Escribe y pulsa Enter"
      @keydown="onKeydown"
      @blur="addTag"
    />
    <datalist :id="`${listId}-suggestions`">
      <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
    </datalist>
  </div>
</template>

<style scoped>
.tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  list-style: none;
  padding: 0;
  margin: 0.25rem 0;
}

.tags li {
  background: var(--color-background-mute);
  border-radius: 999px;
  padding: 0.15rem 0.5rem;
  font-size: 0.85rem;
  display: flex;
  align-items: center;
  gap: 0.3rem;
}

.tags button {
  border: none;
  background: none;
  cursor: pointer;
  font-size: 1rem;
  line-height: 1;
  padding: 0;
}
</style>
