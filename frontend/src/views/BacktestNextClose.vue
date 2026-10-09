<script setup>
import { onMounted, ref } from 'vue'
import * as reportsApi from '../api/reports'
import { formatNumber } from '../utils/format'

// How well the next-close estimate did on past days, against the naive "tomorrow = today" guess.
const data = ref(null)
const loading = ref(true)

onMounted(async () => {
  try {
    data.value = await reportsApi.nextCloseAccuracy()
  } finally {
    loading.value = false
  }
})

const pct = (v) => (v === null || v === undefined ? '—' : `${Number(v).toFixed(2)}%`)
</script>

<template>
  <div>
    <LoadingState v-if="loading" />

    <template v-else-if="data?.available">
      <p class="muted small">Computed {{ new Date(data.computed_at).toLocaleString() }} over {{ formatNumber(data.sample_size) }} past days across {{ formatNumber(data.stocks_used) }} stocks.</p>

      <Card style="margin-top: 12px">
        <table v-align-numbers class="table">
          <thead>
            <tr><th>Measure</th><th>Estimate</th><th>Naive baseline</th><th>Better than baseline?</th></tr>
          </thead>
          <tbody>
            <tr>
              <td>Average error (MAPE) — lower is better</td>
              <td>{{ pct(data.mape) }}</td>
              <td class="muted">{{ pct(data.naive_mape) }}</td>
              <td>
                <span class="badge" :class="data.mape < data.naive_mape ? 'buy' : 'sell'">{{ data.mape < data.naive_mape ? 'Yes' : 'No' }}</span>
              </td>
            </tr>
            <tr>
              <td>Direction right (up or down)</td>
              <td>{{ pct(data.direction_accuracy) }}</td>
              <td class="muted">50.00% (a coin flip)</td>
              <td><span class="badge" :class="data.beats_baseline ? 'buy' : 'sell'">{{ data.beats_baseline ? 'Yes' : 'No' }}</span></td>
            </tr>
          </tbody>
        </table>
      </Card>

      <p class="muted small" style="margin-top: 10px">
        The "naive baseline" just guesses that the next close equals today's close. An estimate only adds value if it
        clears that bar; where it does not, that is shown here as it is.
      </p>
    </template>

    <EmptyState v-else>No next-close backtest has run yet. It is produced by the backtest/next-close cron.</EmptyState>
  </div>
</template>
