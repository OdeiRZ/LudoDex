<script setup lang="ts">
import { computed, ref } from 'vue'

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
const isOpen = ref(false)

// Shows every not-yet-picked suggestion on focus (not only once the user
// starts typing, unlike a plain <datalist>) while still narrowing as they
// type, and free text that matches nothing here still becomes a new tag on
// Enter/blur - see addTag.
const filteredSuggestions = computed(() => {
  const query = draft.value.trim().toLowerCase()

  return props.suggestions.filter(
    (suggestion) =>
      !props.modelValue.includes(suggestion) &&
      (query === '' || suggestion.toLowerCase().includes(query)),
  )
})

function addTag(value: string = draft.value) {
  const trimmed = value.trim()

  if (trimmed && !props.modelValue.includes(trimmed)) {
    emit('update:modelValue', [...props.modelValue, trimmed])
  }

  draft.value = ''
}

function selectSuggestion(suggestion: string) {
  addTag(suggestion)
  isOpen.value = false
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
    isOpen.value = false
  } else if (event.key === 'Escape') {
    isOpen.value = false
  }
}

function onBlur() {
  addTag()
  isOpen.value = false
}
</script>

<template>
  <div class="tag-input">
    <label :for="listId">{{ label }}</label>

    <ul v-if="modelValue.length" class="tags">
      <li v-for="tag in modelValue" :key="tag" class="badge">
        {{ tag }}
        <button type="button" :aria-label="$t('tagInput.remove', { tag })" @click="removeTag(tag)">×</button>
      </li>
    </ul>

    <div class="combobox">
      <input
        :id="listId"
        v-model="draft"
        type="text"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="isOpen"
        autocomplete="off"
        :placeholder="$t('tagInput.placeholder')"
        @keydown="onKeydown"
        @focus="isOpen = true"
        @blur="onBlur"
      />

      <ul v-if="isOpen && filteredSuggestions.length" class="suggestions">
        <li v-for="suggestion in filteredSuggestions" :key="suggestion">
          <!-- mousedown.prevent keeps focus on the input instead of letting
               the browser shift it to this button first, so @blur above
               never fires for this click and the list doesn't vanish
               before selectSuggestion runs. tabindex="-1" keeps these out of
               tab order entirely: without it, Tab moves focus onto a button
               that @blur's own isOpen=false then removes from the DOM,
               dropping focus back to <body>. Keyboard users can still add
               any suggestion by typing its text and pressing Enter. -->
          <button type="button" tabindex="-1" @mousedown.prevent="selectSuggestion(suggestion)">
            {{ suggestion }}
          </button>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  list-style: none;
  padding: 0;
  margin: var(--space-1) 0 var(--space-2);
}

.tags li {
  gap: var(--space-1);
}

.tags button {
  border: none;
  background: none;
  cursor: pointer;
  font-size: 1rem;
  line-height: 1;
  padding: 0;
  color: inherit;
}

.combobox {
  position: relative;
}

.suggestions {
  position: absolute;
  z-index: 10;
  top: calc(100% + var(--space-1));
  left: 0;
  right: 0;
  max-height: 220px;
  overflow-y: auto;
  margin: 0;
  padding: var(--space-1);
  list-style: none;
  background: var(--color-surface);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-card);
}

.suggestions button {
  display: block;
  width: 100%;
  padding: var(--space-2) var(--space-3);
  border: none;
  border-radius: var(--radius-sm);
  background: none;
  color: var(--color-text);
  text-align: left;
  cursor: pointer;
}

.suggestions button:hover {
  background: var(--color-surface-hover);
}
</style>
