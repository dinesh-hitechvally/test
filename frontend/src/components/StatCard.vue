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
}

.label {
  margin: 0 0 6px;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--text-muted);
  font-weight: 600;
}

.value {
  margin: 0;
  font-size: 1.6rem;
  font-weight: 700;
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
