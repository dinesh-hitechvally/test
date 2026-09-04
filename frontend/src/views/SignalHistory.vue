<script setup>
import { computed, onMounted, ref } from 'vue'
import client from '../api/client'

const data = ref(null)
const loading = ref(true)

const LABELS = { strong_buy: 'Strong Buy', buy: 'Buy', hold: 'Hold', sell: 'Sell', strong_sell: 'Strong Sell' }

const rows = computed(() => {
  if (!data.value?.available) return []
  return data.value.stats.map((s) => ({
    ...s,
    label: LABELS[s.signal_type] || s.signal_type,
    beatsBaseline: s.baseline_win_rate !== null && Number(s.win_rate) > Number(s.baseline_win_rate),
  }))
})

async function load() {
  loading.value = true
  const { data: res } = await client.get('/signals/accuracy')
  data.value = res
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Signal History / Accuracy</h1>
    <p class="muted">
      A walk-forward backtest of every historical signal this app has generated — for each one, did price actually
      move the direction the signal implied over the next {{ data?.horizon_days || 10 }} trading days?
    </p>

    <p v-if="loading" class="muted">Loading…</p>

    <template v-else-if="data?.available">
      <p class="muted small">
        Computed {{ new Date(data.computed_at).toLocaleString() }}. {{ data.disclaimer }}
      </p>

      <div class="card" style="margin-top: 12px">
        <table class="table">
          <thead>
            <tr><th>Signal</th><th>Sample Size</th><th>Win Rate</th><th>Baseline Win Rate</th><th>Avg Forward Return</th><th>Beats Baseline?</th></tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.signal_type">
              <td><span class="badge" :class="r.signal_type">{{ r.label }}</span></td>
              <td>{{ r.sample_size.toLocaleString() }}</td>
              <td>{{ r.win_rate }}%</td>
              <td class="muted">{{ r.baseline_win_rate }}%</td>
              <td :class="Number(r.avg_forward_return_pct) > 0 ? 'positive' : Number(r.avg_forward_return_pct) < 0 ? 'negative' : ''">
                {{ Number(r.avg_forward_return_pct) > 0 ? '+' : '' }}{{ r.avg_forward_return_pct }}%
              </td>
              <td>
                <span class="badge" :class="r.beatsBaseline ? 'buy' : 'sell'">{{ r.beatsBaseline ? 'Yes' : 'No' }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="muted small" style="margin-top: 10px">
        "Baseline" is what the same stat looks like on an average, unfiltered trading day — a signal only adds real
        value if its win rate clears that bar. Where it doesn't, that's reported here as-is rather than hidden.
      </p>
    </template>

    <p v-else class="muted">No backtest has run yet.</p>
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
