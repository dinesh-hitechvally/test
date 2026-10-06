<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import * as reportsApi from '../api/reports'
import * as stocksApi from '../api/stocks'
import { useStocksStore } from '../stores/stocks'
import { changeTone, formatPrice, formatSignal } from '../utils/format'
import PriceChart from '../components/charts/PriceChart.vue'

const route = useRoute()
const router = useRouter()
const stocksStore = useStocksStore()

const selected = ref(route.params.symbol || '')
const report = ref(null)
const stockOptions = computed(() => stocksStore.stocks.map((s) => ({ value: s.symbol, label: `${s.symbol} — ${s.company_name}` })))
const prices = ref([])
const loading = ref(false)
const error = ref('')

const RETURN_LABELS = { '1w': '1 Week', '1m': '1 Month', '3m': '3 Months', '6m': '6 Months', '1y': '1 Year', ytd: 'YTD' }

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
      reportsApi.stock(selected.value),
      stocksApi.prices(selected.value, 180),
    ])
    report.value = reportRes
    prices.value = pricesRes
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
    <Card>
      <SearchableSelect v-model="selected" :options="stockOptions" style="max-width: 340px" placeholder="Select a stock…" @change="onSelect" />
    </Card>

    <LoadingState v-if="loading" style="margin-top: 16px" />
    <p v-else-if="error" class="muted" style="margin-top: 16px">{{ error }}</p>

    <template v-else-if="report">
      <div class="grid grid-cards" style="margin-top: 16px">
        <StatCard label="Price" :value="`Rs. ${formatPrice(report.latest_price?.close_price)}`" />
        <StatCard
          label="Today's Change"
          :value="report.change_pct !== null ? `${report.change_pct > 0 ? '+' : ''}${report.change_pct}%` : '—'"
          :tone="changeTone(report.change_pct, 'neutral')"
        />
        <StatCard label="Sector" :value="report.stock.sector || 'No Sector'" />
        <StatCard label="Latest Signal" :value="report.latest_signal ? formatSignal(report.latest_signal.signal) : 'No data'" />
      </div>

      <Card style="margin-top: 16px">
        <div class="card-head">
          <h3 style="margin: 0">{{ report.stock.symbol }} — {{ report.stock.company_name }}</h3>
          <StockLink :symbol="report.stock.symbol" class="btn-secondary btn">
            Full Stock Detail
          </StockLink>
        </div>
        <PriceChart :prices="prices" />
      </Card>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <Card title="Returns">
          <table v-align-numbers class="table">
            <thead>
              <tr><th>Period</th><th>Return</th></tr>
            </thead>
            <tbody>
              <tr v-for="r in returnRows" :key="r.key">
                <td>{{ r.label }}</td>
                <td :class="changeTone(r.value, 'neutral')">{{ r.value !== null ? `${r.value > 0 ? '+' : ''}${r.value}%` : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </Card>
        <Card title="Signals — Last 90 Days">
          <table v-align-numbers class="table">
            <thead>
              <tr><th>Signal</th><th>Days Triggered</th></tr>
            </thead>
            <tbody>
              <tr v-for="signal in ['strong_buy', 'buy', 'hold', 'sell', 'strong_sell']" :key="signal">
                <td><SignalBadge :signal="signal" /></td>
                <td>{{ report.signal_counts_90d[signal] ?? 0 }}</td>
              </tr>
            </tbody>
          </table>
        </Card>
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
