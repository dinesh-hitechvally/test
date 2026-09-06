<script setup>
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: [String, Number, null], default: '' },
  options: { type: Array, required: true }, // [{ value, label }]
  placeholder: { type: String, default: 'Select…' },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'change'])

const open = ref(false)
const query = ref('')
const highlighted = ref(-1)
const inputEl = ref(null)
const rootEl = ref(null)

const selectedOption = computed(() => props.options.find((o) => o.value === props.modelValue) || null)

const displayValue = computed(() => (open.value ? query.value : selectedOption.value?.label ?? ''))

const filteredOptions = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!open.value || !q) return props.options
  return props.options.filter((o) => o.label.toLowerCase().includes(q))
})

watch(
  () => props.modelValue,
  () => {
    if (!open.value) query.value = ''
  }
)

function openList() {
  if (props.disabled) return
  open.value = true
  query.value = ''
  highlighted.value = filteredOptions.value.findIndex((o) => o.value === props.modelValue)
  nextTick(() => inputEl.value?.select())
}

function closeList() {
  open.value = false
  query.value = ''
  highlighted.value = -1
}

function choose(option) {
  emit('update:modelValue', option.value)
  emit('change', option.value)
  closeList()
  inputEl.value?.blur()
}

function onInput() {
  open.value = true
  highlighted.value = 0
}

function moveHighlight(delta) {
  if (!open.value) {
    openList()
    return
  }
  const max = filteredOptions.value.length - 1
  if (max < 0) return
  highlighted.value = Math.min(max, Math.max(0, highlighted.value + delta))
}

function chooseHighlighted() {
  const option = filteredOptions.value[highlighted.value]
  if (option) choose(option)
}

function onBlur() {
  // Delay so a click on an option registers before the list unmounts.
  setTimeout(() => {
    if (!rootEl.value?.contains(document.activeElement)) closeList()
  }, 120)
}
</script>

<template>
  <div ref="rootEl" class="searchable-select" :class="{ disabled }">
    <input
      ref="inputEl"
      class="input"
      :value="displayValue"
      :placeholder="placeholder"
      :disabled="disabled"
      autocomplete="off"
      @focus="openList"
      @click="openList"
      @input="query = $event.target.value; onInput()"
      @blur="onBlur"
      @keydown.down.prevent="moveHighlight(1)"
      @keydown.up.prevent="moveHighlight(-1)"
      @keydown.enter.prevent="chooseHighlighted"
      @keydown.esc="closeList"
    />
    <div v-if="open" class="dropdown">
      <button
        v-for="(option, i) in filteredOptions"
        :key="option.value"
        type="button"
        class="option"
        :class="{ highlighted: i === highlighted, selected: option.value === modelValue }"
        @mousedown.prevent="choose(option)"
        @mouseenter="highlighted = i"
      >
        {{ option.label }}
      </button>
      <p v-if="filteredOptions.length === 0" class="empty muted">No matches</p>
    </div>
  </div>
</template>

<style scoped>
.searchable-select {
  position: relative;
}

.searchable-select.disabled {
  opacity: 0.6;
}

.dropdown {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  max-height: 280px;
  overflow-y: auto;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  box-shadow: var(--shadow-lg);
  z-index: 30;
  padding: 4px;
}

.option {
  display: block;
  width: 100%;
  text-align: left;
  padding: 7px 10px;
  border: none;
  background: none;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.88rem;
  color: var(--text);
  font-family: inherit;
}

.option.highlighted {
  background: var(--primary-soft);
}

.option.selected {
  font-weight: 600;
}

.empty {
  padding: 8px 10px;
  margin: 0;
}
</style>
