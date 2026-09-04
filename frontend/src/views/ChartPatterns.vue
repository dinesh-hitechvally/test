<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'

const matches = ref([])
const loading = ref(true)
const filter = ref('')

const filtered = computed(() => (filter.value ? matches.value.filter((m) => m.signal === filter.value) : matches.value))

function biasClass(signal) {
  return signal === 'bullish' ? 'buy' : signal === 'bearish' ? 'sell' : 'hold'
}

async function load() {
  loading.value = true
  const { data } = await client.get('/patterns/scan')
  matches.value = data
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Chart Patterns</h1>
    <p class="muted">
      Every stock whose most recent candle forms a recognizable pattern (Doji, Hammer, Shooting Star, or an
      Engulfing pattern) — a lighter, faster scan than the deeper per-stock pattern detection on the
      Technical Analysis report, run across the whole market.
    </p>

    <div class="filters">
      <button class="btn-secondary btn" :class="{ active: filter === '' }" @click="filter = ''">All</button>
      <button class="btn-secondary btn" :class="{ active: filter === 'bullish' }" @click="filter = 'bullish'">Bullish</button>
      <button class="btn-secondary btn" :class="{ active: filter === 'bearish' }" @click="filter = 'bearish'">Bearish</button>
      <button class="btn-secondary btn" :class="{ active: filter === 'neutral' }" @click="filter = 'neutral'">Neutral</button>
    </div>

    <p v-if="loading" class="muted" style="margin-top: 12px">Loading…</p>

    <div v-else class="card" style="margin-top: 12px">
      <table class="table">
        <thead><tr><th>Symbol</th><th>Company</th><th>Date</th><th>Pattern</th><th>Bias</th></tr></thead>
        <tbody>
          <tr v-for="m in filtered" :key="m.stock_id">
            <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: m.symbol } }">{{ m.symbol }}</RouterLink></td>
            <td class="muted">{{ m.company_name }}</td>
            <td>{{ m.trade_date }}</td>
            <td><strong>{{ m.pattern }}</strong></td>
            <td><span class="badge" :class="biasClass(m.signal)">{{ m.signal }}</span></td>
          </tr>
          <tr v-if="filtered.length === 0">
            <td colspan="5" class="muted" style="text-align: center; padding: 24px">No matches right now.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.filters {
  display: flex;
  gap: 8px;
}

.filters .active {
  background: #1e293b;
  color: #fff;
  border-color: #1e293b;
}
</style>
