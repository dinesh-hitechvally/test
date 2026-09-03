<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import client from '../api/client'
import { useStocksStore } from '../stores/stocks'
import { formatPrice } from '../utils/format'
import StatCard from '../components/StatCard.vue'
import PriceChart from '../components/PriceChart.vue'

const route = useRoute()
const router = useRouter()
const stocksStore = useStocksStore()

const selected = ref(route.params.symbol || '')
const report = ref(null)
const prices = ref([])
const loading = ref(false)
const error = ref('')

const RETURN_LABELS = { '1w': '1 Week', '1m': '1 Month', '3m': '3 Months', '6m': '6 Months', '1y': '1 Year', ytd: 'YTD' }

function tone(pct) {
  if (pct === null || pct === undefined) return 'neutral'
  return pct > 0 ? 'positive' : pct < 0 ? 'negative' : 'neutral'
}

const returnRows = computed(() => {
  if (!report.value) return []
  return Object.entries(RETURN_LABELS).map(([key, label]) => ({ key, label, value: report.value.returns[key] ?? null }))
})

async function loadReport() {
  if (!selected.value) {
    report.value = null
    prices.value = []
    return
  }

  loading.value = true
  error.value = ''
  try {
    const [reportRes, pricesRes] = await Promise.all([
      client.get(`/reports/stock/${selected.value}`),
      client.get(`/stocks/${selected.value}/prices`, { params: { days: 180 } }),
    ])
    report.value = reportRes.data
    prices.value = pricesRes.data
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load stock report.'
    report.value = null
    prices.value = []
  } finally {
    loading.value = false
  }
}

function onSelect() {
  router.replace({ params: { symbol: selected.value || undefined } })
}

watch(
  () => route.params.symbol,
  (symbol) => {
    selected.value = symbol || ''
    loadReport()
  }
)

onMounted(async () => {
  if (stocksStore.stocks.length === 0) await stocksStore.fetchStocks()
  if (selected.value) await loadReport()
})
</script>

<template>
  <div>
    <h1>Stock Report</h1>
    <p class="muted">Pick a stock to see its returns over standard lookback periods, recent signal activity, and price trend.</p>

    <div class="card">
      <select v-model="selected" class="input" style="max-width: 340px" @change="onSelect">
        <option value="" disabled>Select a stock…</option>
        <option v-for="s in stocksStore.stocks" :key="s.id" :value="s.symbol">{{ s.symbol }} — {{ s.company_name }}</option>
      </select>
    </div>

    <p v-if="loading" class="muted" style="margin-top: 16px">Loading…</p>
    <p v-else-if="error" class="muted" style="margin-top: 16px">{{ error }}</p>

    <template v-else-if="report">
      <div class="grid grid-cards" style="margin-top: 16px">
        <StatCard label="Price" :value="`Rs. ${formatPrice(report.latest_price?.close_price)}`" />
        <StatCard
          label="Today's Change"
          :value="report.change_pct !== null ? `${report.change_pct > 0 ? '+' : ''}${report.change_pct}%` : '—'"
          :tone="tone(report.change_pct)"
        />
        <StatCard label="Sector" :value="report.stock.sector || 'Other'" />
        <StatCard label="Latest Signal" :value="report.latest_signal ? report.latest_signal.signal.replace('_', ' ') : 'No data'" />
      </div>

      <div class="card" style="margin-top: 16px">
        <div class="card-head">
          <h3 style="margin: 0">{{ report.stock.symbol }} — {{ report.stock.company_name }}</h3>
          <RouterLink :to="{ name: 'stock-detail', params: { symbol: report.stock.symbol } }" class="btn-secondary btn">
            Full Stock Detail
          </RouterLink>
        </div>
        <PriceChart :prices="prices" />
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Returns</h3>
          <table class="table">
            <thead>
              <tr><th>Period</th><th>Return</th></tr>
            </thead>
            <tbody>
              <tr v-for="r in returnRows" :key="r.key">
                <td>{{ r.label }}</td>
                <td :class="tone(r.value)">{{ r.value !== null ? `${r.value > 0 ? '+' : ''}${r.value}%` : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="card">
          <h3>Signals — Last 90 Days</h3>
          <table class="table">
            <thead>
              <tr><th>Signal</th><th>Days Triggered</th></tr>
            </thead>
            <tbody>
              <tr v-for="signal in ['strong_buy', 'buy', 'hold', 'sell', 'strong_sell']" :key="signal">
                <td><span class="badge" :class="signal">{{ signal.replace('_', ' ') }}</span></td>
                <td>{{ report.signal_counts_90d[signal] ?? 0 }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <p v-else class="muted" style="margin-top: 16px">Select a stock above to see its report.</p>
  </div>
</template>

<style scoped>
.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}

.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}
</style>
