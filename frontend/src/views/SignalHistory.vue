<script setup>
import { computed, onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import { formatNumber } from '../utils/format'

const data = ref(null)
const loading = ref(true)

const LABELS = { buy: 'Buy', hold: 'Hold', sell: 'Sell' }

const LOW_SAMPLE_THRESHOLD = 200

const allRows = computed(() => {
  if (!data.value?.available) return []
  return data.value.stats.map((s) => ({
    ...s,
    label: LABELS[s.signal_type] || s.signal_type,
    beatsBaseline: s.baseline_win_rate !== null && Number(s.win_rate) > Number(s.baseline_win_rate),
    lowSample: s.sample_size < LOW_SAMPLE_THRESHOLD,
  }))
})
// Every signal of a type, vs the same days grouped by how confident they were (Buy % / Sell % band).
const rows = computed(() => allRows.value.filter((r) => !r.confidence_band))
const bandRows = computed(() => allRows.value.filter((r) => r.confidence_band))

async function load() {
  loading.value = true
  try {
    data.value = await marketApi.signalAccuracy()
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <LoadingState v-if="loading" />

    <template v-else-if="data?.available">
      <p class="muted small">
        Computed {{ new Date(data.computed_at).toLocaleString() }}. {{ data.disclaimer }}
      </p>

      <Card style="margin-top: 12px">
        <table v-align-numbers class="table">
          <thead>
            <tr><th>Signal</th><th>Sample Size</th><th>Win Rate</th><th>Baseline Win Rate</th><th>Avg Forward Return</th><th>Beats Baseline?</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.signal_type">
              <td><span class="badge" :class="r.signal_type">{{ r.label }}</span></td>
              <td>
                {{ formatNumber(r.sample_size) }}
                <span v-if="r.lowSample" class="muted small" title="Fewer than 200 samples — not enough data to trust this win rate yet.">(low sample)</span>
              </td>
              <td :class="{ muted: r.lowSample }">{{ r.win_rate }}%</td>
              <td class="muted">{{ r.baseline_win_rate }}%</td>
              <td :class="Number(r.avg_forward_return_pct) > 0 ? 'positive' : Number(r.avg_forward_return_pct) < 0 ? 'negative' : ''">
                {{ Number(r.avg_forward_return_pct) > 0 ? '+' : '' }}{{ r.avg_forward_return_pct }}%
              </td>
              <td>
                <span class="badge" :class="r.lowSample ? 'hold' : (r.beatsBaseline ? 'buy' : 'sell')">
                  {{ r.lowSample ? 'Too few samples' : (r.beatsBaseline ? 'Yes' : 'No') }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </Card>

      <Card v-if="bandRows.length" title="By Confidence (Buy % / Sell %)" style="margin-top: 12px">
        <table v-align-numbers class="table">
          <thead>
            <tr><th>Signal</th><th>Confidence</th><th>Sample Size</th><th>Win Rate</th><th>Baseline Win Rate</th><th>Avg Forward Return</th><th>Beats Baseline?</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in bandRows" :key="r.signal_type + r.confidence_band">
              <td><span class="badge" :class="r.signal_type">{{ r.label }}</span></td>
              <td>{{ r.confidence_band }}%</td>
              <td>
                {{ formatNumber(r.sample_size) }}
                <span v-if="r.lowSample" class="muted small" title="Fewer than 200 samples — not enough data to trust this win rate yet.">(low sample)</span>
              </td>
              <td :class="{ muted: r.lowSample }">{{ r.win_rate }}%</td>
              <td class="muted">{{ r.baseline_win_rate }}%</td>
              <td :class="Number(r.avg_forward_return_pct) > 0 ? 'positive' : Number(r.avg_forward_return_pct) < 0 ? 'negative' : ''">
                {{ Number(r.avg_forward_return_pct) > 0 ? '+' : '' }}{{ r.avg_forward_return_pct }}%
              </td>
              <td>
                <span class="badge" :class="r.lowSample ? 'hold' : (r.beatsBaseline ? 'buy' : 'sell')">
                  {{ r.lowSample ? 'Too few samples' : (r.beatsBaseline ? 'Yes' : 'No') }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </Card>

      <p class="muted small" style="margin-top: 10px">
        "Baseline" is what the same stat looks like on an average, unfiltered trading day — a signal only adds real
        value if its win rate clears that bar. Where it doesn't, that's reported here as-is rather than hidden.
        The confidence table groups every day by its Buy % (or Sell %), including the bands under the 50% decision line:
        if win rate and average return climb as confidence rises, the percentages mean something; if they don't, the
        weights need rework.
      </p>
    </template>

    <EmptyState v-else>No backtest has run yet.</EmptyState>
  </div>
</template>

<style scoped>
.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}

.small {
  font-size: 0.78rem;
}
</style>
