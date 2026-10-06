<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import * as reportsApi from '../api/reports'
import { changeTone, formatPrice } from '../utils/format'
import SignalDistributionChart from '../components/charts/SignalDistributionChart.vue'
import TrendChart from '../components/charts/TrendChart.vue'
import { useSortableTable } from '../composables/useSortableTable'

const route = useRoute()
const router = useRouter()

const sectors = ref([])
const selected = ref(route.query.name || '')
const report = ref(null)
const sectorOptions = computed(() => sectors.value.map((s) => ({ value: s.sector, label: `${s.sector} (${s.stock_count})` })))

const stocks = useSortableTable(
  computed(() => report.value?.stocks ?? []),
  {
    valueGetters: {
      close: (s) => (s.latest_price?.close_price !== undefined ? Number(s.latest_price.close_price) : null),
      signal: (s) => s.latest_signal?.signal ?? null,
    },
  }
)
const { sorted: sortedStocks } = stocks
const loading = ref(false)
const error = ref('')

const trendLabels = computed(() => report.value?.trend.map((t) => t.trade_date) ?? [])
const breadthSeries = computed(() => [
  { label: 'Advancing', data: report.value?.trend.map((t) => t.advancing) ?? [], color: '#15803d' },
  { label: 'Declining', data: report.value?.trend.map((t) => t.declining) ?? [], color: '#b91c1c' },
])

async function loadSectors() {
  sectors.value = await reportsApi.sectors()
}

async function loadReport() {
  if (!selected.value) {
    report.value = null
    return
  }

  loading.value = true
  error.value = ''
  try {
    report.value = await reportsApi.sector(selected.value)
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
    <p class="muted">Pick a sector to see its today's performance, breadth, and constituent stocks.</p>

    <Card>
      <SearchableSelect v-model="selected" :options="sectorOptions" style="max-width: 340px" placeholder="Select a sector…" @change="onSelect" />
    </Card>

    <LoadingState v-if="loading" style="margin-top: 16px" />
    <p v-else-if="error" class="muted" style="margin-top: 16px">{{ error }}</p>

    <template v-else-if="report">
      <div class="grid grid-cards" style="margin-top: 16px">
        <StatCard label="Stocks" :value="report.totals.stock_count" />
        <StatCard label="Advancing" :value="report.totals.advancing" tone="positive" />
        <StatCard label="Declining" :value="report.totals.declining" tone="negative" />
        <StatCard
          label="Avg Change"
          :value="report.avg_change_pct !== null ? `${report.avg_change_pct > 0 ? '+' : ''}${report.avg_change_pct}%` : '—'"
          :tone="changeTone(report.avg_change_pct, 'neutral')"
        />
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <Card title="Signal Distribution">
          <SignalDistributionChart :counts="report.signal_counts" />
        </Card>
        <Card title="Advancing vs. Declining — Last 30 Days">
          <TrendChart :labels="trendLabels" :series="breadthSeries" />
        </Card>
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <Card title="Top Gainers">
          <table v-align-numbers class="table">
            <tbody>
              <tr v-for="s in report.top_gainers" :key="s.id">
                <td><StockLink :symbol="s.symbol" /></td>
                <td :class="changeTone(s.change_pct)">{{ s.change_pct > 0 ? '+' : '' }}{{ s.change_pct }}%</td>
              </tr>
            </tbody>
          </table>
          <EmptyState v-if="report.top_gainers.length === 0">Not enough data yet.</EmptyState>
        </Card>
        <Card title="Top Losers">
          <table v-align-numbers class="table">
            <tbody>
              <tr v-for="s in report.top_losers" :key="s.id">
                <td><StockLink :symbol="s.symbol" /></td>
                <td :class="changeTone(s.change_pct)">{{ s.change_pct > 0 ? '+' : '' }}{{ s.change_pct }}%</td>
              </tr>
            </tbody>
          </table>
          <EmptyState v-if="report.top_losers.length === 0">Not enough data yet.</EmptyState>
        </Card>
      </div>

      <Card :title="`All Stocks in ${report.sector}`" style="margin-top: 16px">
        <table v-align-numbers class="table">
          <thead>
            <tr>
              <SortableTh :table="stocks" column="symbol">Symbol</SortableTh>
              <SortableTh :table="stocks" column="company_name">Company</SortableTh>
              <SortableTh :table="stocks" column="close">Price</SortableTh>
              <SortableTh :table="stocks" column="change_pct">Change</SortableTh>
              <SortableTh :table="stocks" column="signal">Signal</SortableTh>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in sortedStocks" :key="s.id">
              <td><StockLink :symbol="s.symbol" /></td>
              <td class="muted">{{ s.company_name }}</td>
              <td>Rs. {{ formatPrice(s.latest_price?.close_price) }}</td>
              <td :class="changeTone(s.change_pct)">{{ s.change_pct !== null ? `${s.change_pct > 0 ? '+' : ''}${s.change_pct}%` : '—' }}</td>
              <td>
                <SignalBadge v-if="s.latest_signal" :signal="s.latest_signal.signal" />
                <span v-else class="muted">No data</span>
              </td>
            </tr>
          </tbody>
        </table>
      </Card>
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
