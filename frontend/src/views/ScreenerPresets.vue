<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'

const stocks = ref([])
const fiftyTwoWeek = ref([])
const loading = ref(true)
const activePreset = ref('momentum')

const PRESETS = [
  { key: 'momentum', label: 'Momentum', description: 'Bullish signal, RSI 50-70 (trending up, not yet overbought), and up 2%+ today.' },
  { key: 'oversold', label: 'Oversold', description: 'RSI below 30 — a rebound candidate, not a guarantee.' },
  { key: 'overbought', label: 'Overbought', description: 'RSI above 70 — extended, watch for a pullback.' },
  { key: 'near-low', label: 'Near 52-Week Low', description: 'Within 5% of its 52-week low — the closest thing to a real "value" screen without valuation data (P/E, P/B) tracked yet.' },
]

const rsiByStock = computed(() => new Map(stocks.value.map((s) => [s.id, s.latest_indicator?.rsi_14 !== undefined && s.latest_indicator?.rsi_14 !== null ? Number(s.latest_indicator.rsi_14) : null])))
const lowByStock = computed(() => new Map(fiftyTwoWeek.value.map((r) => [r.stock_id, r.pct_from_low])))

const results = computed(() => {
  if (activePreset.value === 'momentum') {
    return stocks.value.filter((s) => {
      const rsi = rsiByStock.value.get(s.id)
      const signal = s.latest_signal?.signal
      return rsi !== null && rsi >= 50 && rsi <= 70 && ['buy', 'strong_buy'].includes(signal) && (s.change_pct ?? 0) >= 2
    })
  }
  if (activePreset.value === 'oversold') {
    return stocks.value.filter((s) => (rsiByStock.value.get(s.id) ?? 100) < 30)
  }
  if (activePreset.value === 'overbought') {
    return stocks.value.filter((s) => (rsiByStock.value.get(s.id) ?? 0) > 70)
  }
  if (activePreset.value === 'near-low') {
    return stocks.value.filter((s) => {
      const pct = lowByStock.value.get(s.id)
      return pct !== null && pct !== undefined && pct <= 5
    })
  }
  return []
})

function changeTone(pct) {
  if (pct === null || pct === undefined) return ''
  return pct > 0 ? 'positive' : pct < 0 ? 'negative' : ''
}

async function load() {
  loading.value = true
  const [screenerRes, weekRes] = await Promise.all([client.get('/market/screener'), client.get('/market/52-week')])
  stocks.value = screenerRes.data
  fiftyTwoWeek.value = weekRes.data
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Preset Screens</h1>
    <p class="muted">One-click screens built from real, already-tracked data (RSI, signals, 52-week range) — not fitted or backtested, just common-sense filters.</p>

    <div class="filters">
      <button v-for="p in PRESETS" :key="p.key" class="btn-secondary btn" :class="{ active: activePreset === p.key }" @click="activePreset = p.key">
        {{ p.label }}
      </button>
    </div>

    <p class="muted" style="margin-top: 8px">{{ PRESETS.find((p) => p.key === activePreset)?.description }}</p>

    <p v-if="loading" class="muted">Loading…</p>
    <div v-else class="card" style="margin-top: 12px">
      <p class="muted">{{ results.length }} stock{{ results.length === 1 ? '' : 's' }} match.</p>
      <table class="table">
        <thead><tr><th>Symbol</th><th>Company</th><th>Price</th><th>% Change</th><th>RSI</th><th>Signal</th></tr></thead>
        <tbody>
          <tr v-for="s in results" :key="s.id">
            <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
            <td class="muted">{{ s.company_name }}</td>
            <td>{{ formatPrice(s.latest_price?.close_price) }}</td>
            <td :class="changeTone(s.change_pct)">{{ s.change_pct !== null && s.change_pct !== undefined ? `${s.change_pct > 0 ? '+' : ''}${s.change_pct}%` : '—' }}</td>
            <td>{{ rsiByStock.get(s.id) !== null && rsiByStock.get(s.id) !== undefined ? rsiByStock.get(s.id).toFixed(1) : '—' }}</td>
            <td>
              <span v-if="s.latest_signal" class="badge" :class="s.latest_signal.signal">{{ s.latest_signal.signal.replace('_', ' ') }}</span>
              <span v-else class="muted">No data</span>
            </td>
          </tr>
          <tr v-if="results.length === 0">
            <td colspan="6" class="muted" style="text-align: center; padding: 24px">No stocks match this screen right now.</td>
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
  flex-wrap: wrap;
  margin-top: 4px;
}

.filters .active {
  background: #1e293b;
  color: #fff;
  border-color: #1e293b;
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}
</style>
