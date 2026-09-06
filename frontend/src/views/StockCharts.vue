<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import client from '../api/client'
import { useStocksStore } from '../stores/stocks'
import { toHeikinAshi } from '../utils/chartTransforms'
import CandlestickChart from '../components/CandlestickChart.vue'
import OhlcBarChart from '../components/OhlcBarChart.vue'
import PriceLineChart from '../components/PriceLineChart.vue'
import RenkoChart from '../components/RenkoChart.vue'
import KagiChart from '../components/KagiChart.vue'
import PointFigureChart from '../components/PointFigureChart.vue'
import TrendChart from '../components/TrendChart.vue'
import SectorPerformanceChart from '../components/SectorPerformanceChart.vue'
import StatCard from '../components/StatCard.vue'
import SearchableSelect from '../components/SearchableSelect.vue'

const route = useRoute()
const router = useRouter()
const stocksStore = useStocksStore()

const REPORT_TYPES = [
  { key: 'overall', label: 'Overall' },
  { key: 'sector', label: 'Sector' },
  { key: 'stock', label: 'Stock' },
]

const CHART_TYPES = [
  { key: 'candlestick', label: 'Candlestick' },
  { key: 'line', label: 'Line' },
  { key: 'area', label: 'Area' },
  { key: 'bar', label: 'Bar (OHLC)' },
  { key: 'heikin', label: 'Heikin Ashi' },
  { key: 'pointfigure', label: 'Point & Figure' },
  { key: 'renko', label: 'Renko' },
  { key: 'kagi', label: 'Kagi' },
]

const reportTypeOptions = REPORT_TYPES.map((t) => ({ value: t.key, label: t.label }))
const chartTypeOptions = CHART_TYPES.map((c) => ({ value: c.key, label: c.label }))

const reportType = ref(route.params.symbol ? 'stock' : 'overall')
const selectedSector = ref('')
const selectedStock = ref(route.params.symbol || '')
const chartType = ref('candlestick')

const INDEX_COLORS = ['#2563eb', '#dc2626', '#15803d', '#d97706']

function changeTone(value) {
  if (value === null || value === undefined) return ''
  return value > 0 ? 'positive' : value < 0 ? 'negative' : ''
}

// --- Overall (market-wide) ---
const indices = ref([])
const marketTrend = ref(null)
const overallLoading = ref(false)
let overallLoaded = false

async function loadOverall() {
  if (overallLoaded) return
  overallLoading.value = true
  try {
    const [indicesRes, marketRes] = await Promise.all([client.get('/indices'), client.get('/reports/market')])
    indices.value = indicesRes.data
    marketTrend.value = marketRes.data
    overallLoaded = true
  } finally {
    overallLoading.value = false
  }
}

const breadthSeries = computed(() => [
  { label: 'Advancing', data: marketTrend.value?.trend.map((t) => t.advancing) ?? [], color: '#15803d' },
  { label: 'Declining', data: marketTrend.value?.trend.map((t) => t.declining) ?? [], color: '#b91c1c' },
])
const trendLabels = computed(() => marketTrend.value?.trend.map((t) => t.trade_date) ?? [])

// --- Sector ---
const sectors = ref([])
const sectorPerformance = ref([])
const sectorTrendData = ref(null)
const sectorLoading = ref(false)
let sectorListLoaded = false
const sectorOptions = computed(() => sectors.value.map((s) => ({ value: s.sector, label: `${s.sector} (${s.stock_count})` })))
const stockOptions = computed(() => stocksStore.stocks.map((s) => ({ value: s.symbol, label: `${s.symbol} — ${s.company_name}` })))

async function loadSectorList() {
  if (sectorListLoaded) return
  const [sectorsRes, marketRes] = await Promise.all([client.get('/reports/sectors'), client.get('/reports/market')])
  sectors.value = sectorsRes.data
  sectorPerformance.value = marketRes.data.sector_performance
  sectorListLoaded = true
  if (sectors.value.length && !selectedSector.value) selectedSector.value = sectors.value[0].sector
}

async function loadSectorTrend() {
  if (!selectedSector.value) return
  sectorLoading.value = true
  try {
    const { data } = await client.get('/reports/sector', { params: { name: selectedSector.value } })
    sectorTrendData.value = data
  } finally {
    sectorLoading.value = false
  }
}

const sectorTrendLabels = computed(() => sectorTrendData.value?.trend.map((t) => t.trade_date) ?? [])
const sectorTrendSeries = computed(() => [
  { label: 'Advancing', data: sectorTrendData.value?.trend.map((t) => t.advancing) ?? [], color: '#15803d' },
  { label: 'Declining', data: sectorTrendData.value?.trend.map((t) => t.declining) ?? [], color: '#b91c1c' },
])

watch(selectedSector, loadSectorTrend)

// --- Stock ---
const days = ref(180)
const prices = ref([])
const stockLoading = ref(false)

// 100000 trading days is longer than any NEPSE listing's history could ever
// be — a plain sentinel for "everything stored", not a real day count. The
// backend's LIMIT just returns fewer rows when a stock has less than that,
// so this needs no backend change to reach all the way back to listing date.
const MAX_HISTORY_DAYS = 100000

const RANGES = [
  { label: '3M', value: 90 },
  { label: '6M', value: 180 },
  { label: '1Y', value: 365 },
  { label: '2Y', value: 730 },
  { label: 'Max', value: MAX_HISTORY_DAYS },
]

async function loadStock() {
  if (!selectedStock.value) {
    prices.value = []
    return
  }

  stockLoading.value = true
  try {
    const { data } = await client.get(`/stocks/${selectedStock.value}/prices`, { params: { days: days.value } })
    prices.value = data
  } finally {
    stockLoading.value = false
  }
}

const heikinPrices = computed(() => toHeikinAshi(prices.value))

watch(days, () => {
  if (reportType.value === 'stock') loadStock()
})

watch(
  () => route.params.symbol,
  (symbol) => {
    if (symbol === selectedStock.value) return
    selectedStock.value = symbol || ''
    if (symbol) {
      reportType.value = 'stock'
      loadStock()
    }
  }
)

function onReportTypeChange() {
  if (reportType.value === 'overall') {
    selectedStock.value = ''
    loadOverall()
  } else if (reportType.value === 'sector') {
    selectedStock.value = ''
    loadSectorList()
    loadSectorTrend()
  } else if (reportType.value === 'stock' && selectedStock.value) {
    loadStock()
  }

  // Explicit route name so Vue Router recomputes the path from scratch —
  // a params-only replace() merged against the current route was silently
  // keeping a stale /technical/charts/SYMBOL in the URL after switching
  // away from Stock, instead of dropping the param.
  router.replace({ name: 'technical-charts', params: { symbol: selectedStock.value || undefined } })
}

function onSelectSector() {
  loadSectorTrend()
}

function onSelectStock() {
  router.replace({ name: 'technical-charts', params: { symbol: selectedStock.value || undefined } })
  loadStock()
}

onMounted(async () => {
  if (stocksStore.stocks.length === 0) await stocksStore.fetchStocks()
  await loadSectorList() // populate the sector dropdown regardless of active report type

  if (reportType.value === 'stock') await loadStock()
  else await loadOverall()
})
</script>

<template>
  <div>
    <h1>Stock Charts</h1>
    <p class="muted">Pick a report type, then narrow it down with a sector or stock and a chart style.</p>

    <div class="card">
      <div class="picker-row">
        <label class="picker-field">
          <span class="picker-label">Report Type</span>
          <SearchableSelect v-model="reportType" :options="reportTypeOptions" @change="onReportTypeChange" />
        </label>

        <label class="picker-field">
          <span class="picker-label">Sector</span>
          <SearchableSelect v-model="selectedSector" :options="sectorOptions" :disabled="reportType !== 'sector'" @change="onSelectSector" />
        </label>

        <label class="picker-field">
          <span class="picker-label">Stock</span>
          <SearchableSelect
            v-model="selectedStock"
            :options="stockOptions"
            :disabled="reportType !== 'stock'"
            placeholder="Select a stock…"
            @change="onSelectStock"
          />
        </label>

        <label class="picker-field">
          <span class="picker-label">Chart Type</span>
          <SearchableSelect v-model="chartType" :options="chartTypeOptions" :disabled="reportType !== 'stock'" />
        </label>

        <div v-if="reportType === 'stock'" class="ranges">
          <button v-for="r in RANGES" :key="r.value" class="btn-secondary btn" :class="{ active: days === r.value }" @click="days = r.value; loadStock()">
            {{ r.label }}
          </button>
        </div>
      </div>
      <p v-if="reportType !== 'stock'" class="muted small">Chart Type only applies to the Stock report — Overall and Sector use trend charts, since there's no per-day open/high/low/close for a sector or the whole market.</p>
    </div>

    <!-- Overall -->
    <template v-if="reportType === 'overall'">
      <p v-if="overallLoading" class="muted" style="margin-top: 16px">Loading…</p>
      <template v-else-if="marketTrend">
        <div class="grid grid-cards" style="margin-top: 16px">
          <StatCard
            v-for="idx in indices"
            :key="idx.index_name"
            :label="idx.index_name"
            :value="Number(idx.latest.close).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })"
            :tone="changeTone(Number(idx.latest.change_pct))"
            :sub="idx.latest.change_pct !== null ? `${Number(idx.latest.change_pct) > 0 ? '+' : ''}${idx.latest.change_pct}%` : ''"
          />
        </div>

        <div class="card" style="margin-top: 16px" v-for="(idx, i) in indices" :key="`chart-${idx.index_name}`">
          <h3>{{ idx.index_name }}</h3>
          <TrendChart :labels="idx.history.map((h) => h.trade_date)" :series="[{ label: idx.index_name, data: idx.history.map((h) => h.close), color: INDEX_COLORS[i % INDEX_COLORS.length] }]" :height="200" />
        </div>

        <div class="card" style="margin-top: 16px">
          <h3>Market Breadth — Last 30 Days</h3>
          <TrendChart :labels="trendLabels" :series="breadthSeries" />
        </div>
      </template>
    </template>

    <!-- Sector -->
    <template v-else-if="reportType === 'sector'">
      <div class="card" style="margin-top: 16px">
        <h3>Sector Performance (Today)</h3>
        <SectorPerformanceChart :sectors="sectorPerformance" />
      </div>

      <p v-if="sectorLoading" class="muted" style="margin-top: 16px">Loading…</p>
      <div class="card" style="margin-top: 16px" v-else-if="sectorTrendData">
        <h3>{{ selectedSector }} — Advancing vs. Declining (Last 30 Days)</h3>
        <TrendChart :labels="sectorTrendLabels" :series="sectorTrendSeries" />
      </div>
    </template>

    <!-- Stock -->
    <template v-else>
      <p v-if="stockLoading" class="muted" style="margin-top: 16px">Loading…</p>
      <p v-else-if="!selectedStock" class="muted" style="margin-top: 16px">Select a stock above to see its chart.</p>
      <div class="card" style="margin-top: 16px" v-else>
        <CandlestickChart v-if="chartType === 'candlestick'" :prices="prices" />
        <CandlestickChart v-else-if="chartType === 'heikin'" :prices="heikinPrices" />
        <OhlcBarChart v-else-if="chartType === 'bar'" :prices="prices" />
        <PriceLineChart v-else-if="chartType === 'line'" :prices="prices" />
        <PriceLineChart v-else-if="chartType === 'area'" :prices="prices" area />
        <RenkoChart v-else-if="chartType === 'renko'" :prices="prices" />
        <KagiChart v-else-if="chartType === 'kagi'" :prices="prices" />
        <PointFigureChart v-else-if="chartType === 'pointfigure'" :prices="prices" />
      </div>
    </template>
  </div>
</template>

<style scoped>
.picker-row {
  display: flex;
  align-items: flex-end;
  gap: 16px;
  flex-wrap: wrap;
}

.picker-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 0.8rem;
  color: var(--text-muted);
}

.picker-field .input {
  min-width: 200px;
}

.ranges {
  display: flex;
  gap: 6px;
}

.ranges .active {
  background: #1e293b;
  color: #fff;
  border-color: #1e293b;
}

.small {
  font-size: 0.78rem;
  margin-top: 10px;
  margin-bottom: 0;
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}
</style>
