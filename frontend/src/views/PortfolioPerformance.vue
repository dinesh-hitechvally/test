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
import client, { apiBaseUrl } from '../api/client'
import { usePortfolioStore } from '../stores/portfolio'
import { formatPrice } from '../utils/format'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend)

const store = usePortfolioStore()
const history = ref([])
const loading = ref(true)

const exportUrl = computed(() => `${apiBaseUrl}/api/portfolios/${store.activePortfolioId}/export`)

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

const latest = computed(() => history.value.at(-1))

async function load() {
  loading.value = true
  if (store.portfolios.length === 0) await store.fetchPortfolios()
  if (store.activePortfolioId) {
    const { data } = await client.get(`/portfolios/${store.activePortfolioId}/performance`)
    history.value = data
  }
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <div class="page-header">
      <h1>Portfolio Performance</h1>
      <a v-if="store.activePortfolioId" class="btn-secondary btn" :href="exportUrl">Export CSV</a>
    </div>

    <p v-if="loading" class="muted">Loading…</p>
    <p v-else-if="history.length === 0" class="muted card">
      No performance history yet — add a buy transaction on the Portfolio page to start tracking value over time.
    </p>
    <template v-else>
      <div class="grid grid-cards">
        <div class="card">
          <p class="muted" style="margin: 0 0 4px; font-size: 0.75rem; text-transform: uppercase; font-weight: 600">Current Value</p>
          <p style="margin: 0; font-size: 1.4rem; font-weight: 700">Rs. {{ formatPrice(latest?.value) }}</p>
        </div>
        <div class="card">
          <p class="muted" style="margin: 0 0 4px; font-size: 0.75rem; text-transform: uppercase; font-weight: 600">Invested (as of latest)</p>
          <p style="margin: 0; font-size: 1.4rem; font-weight: 700">Rs. {{ formatPrice(latest?.invested) }}</p>
        </div>
      </div>

      <div class="card" style="margin-top: 16px">
        <div style="height: 360px">
          <Line :data="chartData" :options="options" />
        </div>
      </div>
    </template>
  </div>
</template>
