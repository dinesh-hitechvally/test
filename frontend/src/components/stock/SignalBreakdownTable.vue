<script setup>
import { computed } from 'vue'

// One day's Buy / Sell / Hold breakdown: final %, each category (with its weight) and every condition under it.
// `signal` is a Signal with its `breakdown { buy_pct sell_pct hold_pct hold_type category_scores conditions }`.
const props = defineProps({
  signal: { type: Object, required: true },
})

// Mirrors config/signals.php `weights` — how much each category counts towards the final Buy / Sell / Hold %.
const CATEGORY_WEIGHTS = [
  ['technical', 'Technical', 25],
  ['fundamental', 'Fundamental', 25],
  ['trend', 'Trend', 15],
  ['momentum', 'Momentum', 10],
  ['volume', 'Volume', 10],
  ['risk', 'Risk', 10],
  ['valuation', 'Valuation', 5],
]

const HOLD_TYPE_LABELS = {
  long_term: 'Long-term strength',
  consolidation: 'Consolidation',
  wait_confirmation: 'Waiting for confirmation',
  profit_protection: 'Profit protection',
  temporary_weakness: 'Temporary weakness',
  overbought: 'Overbought',
}

const breakdown = computed(() => props.signal.breakdown || null)

const rows = computed(() => {
  const scores = breakdown.value?.category_scores
  if (!scores) return []
  return CATEGORY_WEIGHTS.map(([key, label, weight]) => ({
    key,
    label,
    weight,
    triple: scores[key] ?? null,
    conditions: breakdown.value.conditions?.[key] ?? [],
  }))
})

const pct = (v) => `${Number(v).toFixed(1)}%`
</script>

<template>
  <div v-if="breakdown">
    <p>
      <SignalBadge :signal="signal.signal" />
      <strong class="positive" style="margin-left: 12px">Buy {{ pct(breakdown.buy_pct) }}</strong>
      <strong class="negative" style="margin-left: 12px">Sell {{ pct(breakdown.sell_pct) }}</strong>
      <strong class="muted" style="margin-left: 12px">Hold {{ pct(breakdown.hold_pct) }}</strong>
      <span v-if="breakdown.hold_type" class="muted" style="margin-left: 12px">· {{ HOLD_TYPE_LABELS[breakdown.hold_type] || breakdown.hold_type }}</span>
    </p>

    <table v-if="rows.length" v-align-numbers class="table">
      <thead>
        <tr><th>Category</th><th>Weight</th><th>Buy</th><th>Sell</th><th>Hold</th></tr>
      </thead>
      <tbody>
        <template v-for="row in rows" :key="row.key">
          <tr>
            <td>{{ row.label }}</td>
            <td>{{ row.weight }}%</td>
            <template v-if="row.triple">
              <td class="positive">{{ pct(row.triple.buy) }}</td>
              <td class="negative">{{ pct(row.triple.sell) }}</td>
              <td class="muted">{{ pct(row.triple.hold) }}</td>
            </template>
            <td v-else colspan="3" class="muted">No data</td>
          </tr>
          <tr v-for="c in row.conditions" :key="row.key + c.key">
            <td class="muted small" style="padding-left: 24px">{{ c.label }}: {{ c.result }}</td>
            <td></td>
            <td class="small">{{ pct(c.buy) }}</td>
            <td class="small">{{ pct(c.sell) }}</td>
            <td class="small">{{ pct(c.hold) }}</td>
          </tr>
        </template>
        <tr>
          <td><strong>Final</strong></td>
          <td>100%</td>
          <td class="positive"><strong>{{ pct(breakdown.buy_pct) }}</strong></td>
          <td class="negative"><strong>{{ pct(breakdown.sell_pct) }}</strong></td>
          <td class="muted"><strong>{{ pct(breakdown.hold_pct) }}</strong></td>
        </tr>
      </tbody>
    </table>
    <p class="muted small">
      Every condition gets its own Buy / Sell / Hold %. A category is the average of its conditions; the final % is the
      categories weighted as above (a category with no data is left out and the rest re-weighted). Buy or Sell needs at
      least 50%, otherwise it is a Hold. Fundamental and Valuation only exist for the latest day.
    </p>
  </div>
  <p v-else class="muted small">No breakdown stored for this day.</p>
</template>
