<script setup>
import { onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import { formatNumber } from '../utils/format'

// The price-direction model's out-of-sample record against "always guess the more common direction".
const model = ref(null)
const loading = ref(true)

onMounted(async () => {
  try {
    model.value = await marketApi.mlModel()
  } finally {
    loading.value = false
  }
})

const pct = (v) => (v === null || v === undefined ? '—' : `${(Number(v) * 100).toFixed(2)}%`)
</script>

<template>
  <div>
    <LoadingState v-if="loading" />

    <template v-else-if="model">
      <p class="muted small">
        Trained {{ new Date(model.trained_at).toLocaleString() }} on {{ formatNumber(model.stocks_used) }} stocks —
        {{ formatNumber(model.train_samples) }} training days, tested on the most recent {{ formatNumber(model.test_samples) }}
        it never saw. It predicts whether the close is higher {{ model.horizon_days }} trading days later.
      </p>

      <Card style="margin-top: 12px">
        <table v-align-numbers class="table">
          <thead>
            <tr><th>Measure</th><th>Model</th><th>Baseline</th><th>Beats baseline?</th></tr>
          </thead>
          <tbody>
            <tr>
              <td>Accuracy (out-of-sample)</td>
              <td>{{ pct(model.accuracy) }}</td>
              <td class="muted">{{ pct(model.baseline_accuracy) }}</td>
              <td><span class="badge" :class="model.beats_baseline ? 'buy' : 'sell'">{{ model.beats_baseline ? 'Yes' : 'No' }}</span></td>
            </tr>
            <tr><td>Precision (when it says "up", how often it is)</td><td>{{ pct(model.precision) }}</td><td class="muted">—</td><td></td></tr>
            <tr><td>Recall (share of real "up" days it caught)</td><td>{{ pct(model.recall) }}</td><td class="muted">—</td><td></td></tr>
            <tr><td>F1</td><td>{{ Number(model.f1).toFixed(4) }}</td><td class="muted">—</td><td></td></tr>
          </tbody>
        </table>
      </Card>

      <p class="muted small" style="margin-top: 10px">
        The baseline always guesses whichever direction was more common in the test period. In a market that mostly
        trends one way that is a hard bar; a model that does not clear it is reported honestly rather than hidden.
      </p>
    </template>

    <EmptyState v-else>No model has been trained yet. It is trained by the generate/ml-model cron.</EmptyState>
  </div>
</template>
