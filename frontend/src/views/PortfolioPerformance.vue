<script setup>
import { computed, onMounted, ref } from 'vue'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Tooltip,
  Legend,
} from 'chart.js'
import { downloadFile } from '../api/client'
import * as portfolioApi from '../api/portfolio'
import { usePortfolioStore } from '../stores/portfolio'
import { changeTone, formatPrice } from '../utils/format'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend)

const store = usePortfolioStore()
const history = ref([])
const metrics = ref(null)
const loading = ref(true)
const exportError = ref('')

// A plain <a href> can't carry the Authorization header the API needs, so
// this fetches the file (with it) and saves it via downloadFile() instead.
async function exportCsv() {
  exportError.value = ''
  try {
    await downloadFile(`/portfolios/${store.activePortfolioId}/export`)
  } catch {
    exportError.value = 'Export failed.'
  }
}

const chartData = computed(() => ({
  labels: history.value.map((h) => h.date),
  datasets: [
    {
      label: 'Value',
      data: history.value.map((h) => h.value),
      borderColor: '#2563eb',
      backgroundColor: 'transparent',
      pointRadius: 0,
      borderWidth: 2,
    },
    {
      label: 'Invested',
      data: history.value.map((h) => h.invested),
      borderColor: '#94a3b8',
      backgroundColor: 'transparent',
      pointRadius: 0,
      borderWidth: 1.5,
      borderDash: [6, 4],
    },
  ],
}))

const options = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  scales: { x: { ticks: { maxTicksLimit: 10 } } },
}

async function load() {
  loading.value = true
  try {
    if (store.portfolios.length === 0) await store.fetchPortfolios()
    if (store.activePortfolioId) {
      const data = await portfolioApi.performance(store.activePortfolioId)
      history.value = data.history
      metrics.value = data.metrics
    }
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div v-if="store.activePortfolioId" class="page-header" style="justify-content: flex-end">
      <a v-if="store.activePortfolioId" href="#" class="btn-secondary btn" @click.prevent="exportCsv">Export CSV</a>
    </div>
    <p v-if="exportError" class="error-text">{{ exportError }}</p>

    <LoadingState v-if="loading" />
    <EmptyState v-else-if="history.length === 0" class="card">
      No performance history yet — add a buy transaction on the Portfolio page to start tracking value over time.
    </EmptyState>
    <template v-else>
      <div class="grid grid-cards">
        <StatCard label="Current Value" :value="`Rs. ${formatPrice(metrics.current_value)}`" />
        <StatCard label="Total Invested" :value="`Rs. ${formatPrice(metrics.total_invested)}`" />
        <StatCard
          label="Total P&L"
          :value="`Rs. ${formatPrice(metrics.total_pnl)}`"
          :tone="changeTone(metrics.total_pnl, 'neutral')"
          :sub="metrics.total_pnl_pct !== null ? `${metrics.total_pnl_pct > 0 ? '+' : ''}${metrics.total_pnl_pct}%` : ''"
        />
        <StatCard
          label="XIRR (Annualized)"
          :value="metrics.xirr_pct !== null ? `${metrics.xirr_pct > 0 ? '+' : ''}${metrics.xirr_pct}%` : 'N/A'"
          :tone="changeTone(metrics.xirr_pct, 'neutral')"
          sub="Money-weighted return"
        />
      </div>

      <Card style="margin-top: 16px">
        <div style="height: 360px">
          <Line :data="chartData" :options="options" />
        </div>
      </Card>

      <div class="grid grid-cards" style="margin-top: 16px">
        <StatCard
          label="Best Day"
          :value="metrics.best_day ? `Rs. ${formatPrice(metrics.best_day.change)}` : '—'"
          tone="positive"
          :sub="metrics.best_day?.date"
        />
        <StatCard
          label="Worst Day"
          :value="metrics.worst_day ? `Rs. ${formatPrice(metrics.worst_day.change)}` : '—'"
          tone="negative"
          :sub="metrics.worst_day?.date"
        />
        <StatCard
          label="All-Time High"
          :value="metrics.all_time_high ? `Rs. ${formatPrice(metrics.all_time_high.value)}` : '—'"
          :sub="metrics.all_time_high?.date"
        />
      </div>

      <p class="muted small">
        Best/Worst Day measures the single biggest day-over-day swing in unrealized P&L (not raw value), so adding
        new money on a given day isn't itself counted as a "gain." XIRR is the annualized, money-weighted return
        across every buy/sell and today's value — the fair way to judge performance when contributions weren't a
        single lump sum on day one.
      </p>
    </template>
  </div>
</template>

<style scoped>
.small {
  font-size: 0.78rem;
  margin-top: 12px;
}
</style>
