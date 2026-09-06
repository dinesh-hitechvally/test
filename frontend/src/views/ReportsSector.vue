<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'
import StatCard from '../components/StatCard.vue'
import SignalDistributionChart from '../components/SignalDistributionChart.vue'
import TrendChart from '../components/TrendChart.vue'
import SearchableSelect from '../components/SearchableSelect.vue'

const route = useRoute()
const router = useRouter()

const sectors = ref([])
const selected = ref(route.query.name || '')
const report = ref(null)
const sectorOptions = computed(() => sectors.value.map((s) => ({ value: s.sector, label: `${s.sector} (${s.stock_count})` })))
const loading = ref(false)
const error = ref('')

function changeTone(pct) {
  if (pct === null || pct === undefined) return ''
  return pct > 0 ? 'positive' : pct < 0 ? 'negative' : ''
}

const trendLabels = computed(() => report.value?.trend.map((t) => t.trade_date) ?? [])
const breadthSeries = computed(() => [
  { label: 'Advancing', data: report.value?.trend.map((t) => t.advancing) ?? [], color: '#15803d' },
  { label: 'Declining', data: report.value?.trend.map((t) => t.declining) ?? [], color: '#b91c1c' },
])

async function loadSectors() {
  const { data } = await client.get('/reports/sectors')
  sectors.value = data
}

async function loadReport() {
  if (!selected.value) {
    report.value = null
    return
  }

  loading.value = true
  error.value = ''
  try {
    const { data } = await client.get('/reports/sector', { params: { name: selected.value } })
    report.value = data
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load sector report.'
    report.value = null
  } finally {
    loading.value = false
  }
}

function onSelect() {
  router.replace({ query: selected.value ? { name: selected.value } : {} })
}

watch(
  () => route.query.name,
  (name) => {
    selected.value = name || ''
    loadReport()
  }
)

onMounted(async () => {
  await loadSectors()
  if (selected.value) await loadReport()
})
</script>

<template>
  <div>
    <h1>Sector Report</h1>
    <p class="muted">Pick a sector to see its today's performance, breadth, and constituent stocks.</p>

    <div class="card">
      <SearchableSelect v-model="selected" :options="sectorOptions" style="max-width: 340px" placeholder="Select a sector…" @change="onSelect" />
    </div>

    <p v-if="loading" class="muted" style="margin-top: 16px">Loading…</p>
    <p v-else-if="error" class="muted" style="margin-top: 16px">{{ error }}</p>

    <template v-else-if="report">
      <div class="grid grid-cards" style="margin-top: 16px">
        <StatCard label="Stocks" :value="report.totals.stock_count" />
        <StatCard label="Advancing" :value="report.totals.advancing" tone="positive" />
        <StatCard label="Declining" :value="report.totals.declining" tone="negative" />
        <StatCard
          label="Avg Change"
          :value="report.avg_change_pct !== null ? `${report.avg_change_pct > 0 ? '+' : ''}${report.avg_change_pct}%` : '—'"
          :tone="report.avg_change_pct > 0 ? 'positive' : report.avg_change_pct < 0 ? 'negative' : 'neutral'"
        />
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Signal Distribution</h3>
          <SignalDistributionChart :counts="report.signal_counts" />
        </div>
        <div class="card">
          <h3>Advancing vs. Declining — Last 30 Days</h3>
          <TrendChart :labels="trendLabels" :series="breadthSeries" />
        </div>
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Top Gainers</h3>
          <table class="table">
            <tbody>
              <tr v-for="s in report.top_gainers" :key="s.id">
                <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
                <td :class="changeTone(s.change_pct)">{{ s.change_pct > 0 ? '+' : '' }}{{ s.change_pct }}%</td>
              </tr>
            </tbody>
          </table>
          <p v-if="report.top_gainers.length === 0" class="muted">Not enough data yet.</p>
        </div>
        <div class="card">
          <h3>Top Losers</h3>
          <table class="table">
            <tbody>
              <tr v-for="s in report.top_losers" :key="s.id">
                <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
                <td :class="changeTone(s.change_pct)">{{ s.change_pct > 0 ? '+' : '' }}{{ s.change_pct }}%</td>
              </tr>
            </tbody>
          </table>
          <p v-if="report.top_losers.length === 0" class="muted">Not enough data yet.</p>
        </div>
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>All Stocks in {{ report.sector }}</h3>
        <table class="table">
          <thead>
            <tr><th>Symbol</th><th>Company</th><th>Price</th><th>Change</th><th>Signal</th></tr>
          </thead>
          <tbody>
            <tr v-for="s in report.stocks" :key="s.id">
              <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
              <td class="muted">{{ s.company_name }}</td>
              <td>Rs. {{ formatPrice(s.latest_price?.close_price) }}</td>
              <td :class="changeTone(s.change_pct)">{{ s.change_pct !== null ? `${s.change_pct > 0 ? '+' : ''}${s.change_pct}%` : '—' }}</td>
              <td>
                <span v-if="s.latest_signal" class="badge" :class="s.latest_signal.signal">{{ s.latest_signal.signal.replace('_', ' ') }}</span>
                <span v-else class="muted">No data</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <p v-else class="muted" style="margin-top: 16px">Select a sector above to see its report.</p>
  </div>
</template>

<style scoped>
.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}
</style>
