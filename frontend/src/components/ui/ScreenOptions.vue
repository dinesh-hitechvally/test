<!--
  WordPress-style "Screen Options": a tab at the top right of the page that drops a
  panel of checkboxes. Put it as the first thing on the page, above the heading.

  <ScreenOptions title="Columns" :options="cols.options" :visible="cols.visibleKeys.value"
                 @toggle="cols.toggle" @reset="cols.reset" />
-->
<script setup>
import { ref } from 'vue'

defineProps({
  title: { type: String, default: 'Columns' },
  options: { type: Array, required: true }, // [{ key, label, locked? }]
  visible: { type: Array, required: true }, // keys currently on
  note: { type: String, default: '' },
})
defineEmits(['toggle', 'reset'])

const open = ref(false)
</script>

<template>
  <div class="screen-meta">
    <div v-if="open" class="panel">
      <div class="panel-body">
        <strong class="legend">{{ title }}</strong>
        <label v-for="o in options" :key="o.key" class="option" :class="{ locked: o.locked }">
          <input type="checkbox" :checked="visible.includes(o.key)" :disabled="o.locked" @change="$emit('toggle', o.key)" />
          {{ o.label }}
        </label>
        <button v-if="visible.length < options.length" type="button" class="reset" @click="$emit('reset')">Show all</button>
      </div>
      <p v-if="note" class="note muted">{{ note }}</p>
    </div>

    <div class="tab-row">
      <button type="button" class="tab" :aria-expanded="open" @click="open = !open">
        Screen Options <span class="arrow">{{ open ? '▴' : '▾' }}</span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.screen-meta {
  position: relative;
}

.panel {
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface);
  padding: 12px 16px 10px;
}

.panel-body {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 20px;
  align-items: center;
}

.legend {
  font-size: 0.85rem;
}

.option {
  display: inline-flex;
  gap: 6px;
  align-items: center;
  font-size: 0.9rem;
  cursor: pointer;
}

.option.locked {
  opacity: 0.55;
  cursor: default;
}

.reset {
  border: 0;
  background: none;
  color: var(--primary);
  cursor: pointer;
  font: inherit;
  font-size: 0.85rem;
  padding: 0;
}

.note {
  margin: 8px 0 0;
  font-size: 0.78rem;
}

.tab-row {
  position: absolute;
  right: 25px;
  top: 100%;
  z-index: 5;
}

/* The tab hangs off the panel (or the page edge) like WordPress's. */
.tab {
  border: 1px solid var(--border);
  border-top: 0;
  border-radius: 0 0 8px 8px;
  background: var(--surface);
  color: var(--text-muted);
  padding: 5px 14px;
  font: inherit;
  font-size: 0.82rem;
  cursor: pointer;
}

.tab:hover {
  color: var(--text);
}

.arrow {
  font-size: 0.7rem;
}
</style>
