<script setup>
defineProps({
  label: { type: String, required: true },
  value: { type: [String, Number], required: true },
  sub: { type: String, default: '' },
  tone: { type: String, default: 'neutral' }, // neutral | positive | negative
})
</script>

<template>
  <div class="stat-card card">
    <p class="label">{{ label }}</p>
    <p class="value" :class="tone">{{ value }}</p>
    <p v-if="sub" class="sub muted">{{ sub }}</p>
  </div>
</template>

<style scoped>
.stat-card {
  padding: 16px 18px;
  transition: box-shadow 0.2s ease, transform 0.15s ease;
}

.stat-card:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-1px);
}

.label {
  margin: 0 0 6px;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--text-muted);
  font-weight: 600;
}

.value {
  margin: 0;
  font-size: 1.7rem;
  font-weight: 800;
  letter-spacing: -0.01em;
  font-variant-numeric: tabular-nums;
}

.value.positive {
  color: var(--strong-buy);
}

.value.negative {
  color: var(--strong-sell);
}

.sub {
  margin: 4px 0 0;
  font-size: 0.78rem;
}
</style>
