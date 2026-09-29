<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Number, required: true }, // current page, 1-indexed
  totalPages: { type: Number, required: true },
  siblingCount: { type: Number, default: 1 }, // pages shown on each side of current
  boundaryCount: { type: Number, default: 3 }, // pages pinned at the very start and very end
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

// A separate typed-input buffer rather than binding modelValue directly —
// so mid-typing states (empty, a lone "0", a value that's momentarily out of
// range while backspacing) don't fight the real current-page prop. Vue 3.4+
// auto-casts v-model on type="number" inputs to a real Number (or '' when
// cleared) — so this holds a Number or '', never assume it's a string.
const jumpInput = ref(props.modelValue)

watch(
  () => props.modelValue,
  (v) => {
    jumpInput.value = v
  }
)

function submitJump() {
  // A cleared input comes through as '' (Number('') is 0, not NaN) — check
  // blank explicitly so it resets rather than parsing to 0 and clamping to
  // a spurious jump to page 1.
  const raw = jumpInput.value
  const parsed = raw === '' || raw === null ? NaN : Math.trunc(Number(raw))
  const target = Number.isFinite(parsed) ? Math.min(Math.max(parsed, 1), props.totalPages) : props.modelValue

  if (target === props.modelValue) {
    // No real navigation happening (same page, or an invalid/out-of-range
    // value clamped back to where we already are) — the modelValue watcher
    // won't fire to clean this up on its own, so reset it here instead.
    jumpInput.value = props.modelValue

    return
  }

  go(target)
}

function range(start, end) {
  if (end < start) return []

  return Array.from({ length: end - start + 1 }, (_, i) => start + i)
}

// Builds e.g. [1, 2, 3, '…', 9, 10, 11, '…', 18, 19, 20] — a pinned block of
// `boundaryCount` pages at the start, a sliding window of `boundaryCount`
// pages around wherever the current page is, and another pinned block at
// the end. When the current page's window would touch a boundary block,
// the gap collapses to a single connecting page instead of a 1-page ellipsis
// (an ellipsis should only ever stand in for a real, multi-page gap).
const pages = computed(() => {
  const total = props.totalPages
  const current = props.modelValue
  const boundary = props.boundaryCount
  const sibling = props.siblingCount

  const totalVisible = boundary * 2 + sibling * 2 + 3
  if (total <= totalVisible) {
    return range(1, total)
  }

  const startPages = range(1, boundary)
  const endPages = range(total - boundary + 1, total)

  const siblingsStart = Math.max(Math.min(current - sibling, total - boundary - sibling * 2 - 1), boundary + 2)
  const siblingsEnd = Math.min(Math.max(current + sibling, boundary + sibling * 2 + 2), total - boundary - 1)

  const items = [...startPages]

  if (siblingsStart > boundary + 2) {
    items.push('…')
  } else if (siblingsStart === boundary + 2) {
    items.push(boundary + 1)
  }

  items.push(...range(siblingsStart, siblingsEnd))

  if (siblingsEnd < total - boundary - 1) {
    items.push('…')
  } else if (siblingsEnd === total - boundary - 1) {
    items.push(total - boundary)
  }

  items.push(...endPages)

  return items
})

function go(page) {
  if (props.disabled || page < 1 || page > props.totalPages || page === props.modelValue) return
  emit('update:modelValue', page)
}
</script>

<template>
  <div class="pagination" v-if="totalPages > 1" :class="{ disabled }">
    <button class="btn-secondary btn" :disabled="disabled || modelValue <= 1" @click="go(modelValue - 1)">‹ Prev</button>

    <template v-for="(p, i) in pages" :key="`${p}-${i}`">
      <span v-if="p === '…'" class="ellipsis">…</span>
      <button v-else class="page-btn" :class="{ active: p === modelValue }" :disabled="disabled" @click="go(p)">{{ p }}</button>
    </template>

    <button class="btn-secondary btn" :disabled="disabled || modelValue >= totalPages" @click="go(modelValue + 1)">Next ›</button>

    <form class="jump" novalidate @submit.prevent="submitJump">
      <span class="muted small">Go to</span>
      <input
        v-model="jumpInput"
        type="number"
        min="1"
        :max="totalPages"
        :disabled="disabled"
        class="input jump-input"
        @blur="submitJump"
      />
    </form>
  </div>
</template>

<style scoped>
.pagination {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  margin-top: 14px;
}

.page-btn {
  min-width: 32px;
  height: 32px;
  padding: 0 6px;
  border: 1px solid var(--border);
  background: var(--surface);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.85rem;
  color: var(--text);
  transition: background-color 0.15s ease, border-color 0.15s ease;
}

.page-btn:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
}

.page-btn.active {
  background: var(--primary);
  border-color: var(--primary);
  color: #fff;
  font-weight: 600;
  cursor: default;
}

.ellipsis {
  padding: 0 4px;
  color: var(--text-muted);
}

.pagination.disabled {
  opacity: 0.6;
}

.page-btn:disabled {
  cursor: not-allowed;
}

.jump {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-left: 8px;
}

.jump-input {
  width: 60px;
  padding: 6px 8px;
  text-align: center;
}

.small {
  font-size: 0.78rem;
  white-space: nowrap;
}
</style>
