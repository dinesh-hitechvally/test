<script setup>
import { computed, onMounted } from 'vue'
import { usePortfolioStore } from '../stores/portfolio'
import { formatPrice } from '../utils/format'
import DiversificationChart from '../components/DiversificationChart.vue'

const store = usePortfolioStore()

function bySector(holdings) {
  const totals = {}
  holdings.forEach((h) => {
    if (h.current_value === null) return
    const key = h.sector || 'Other'
    totals[key] = (totals[key] || 0) + h.current_value
  })
  return Object.entries(totals)
    .map(([label, value]) => ({ label, value: Math.round(value) }))
    .sort((a, b) => b.value - a.value)
}

function byStock(holdings) {
  return holdings
    .filter((h) => h.current_value !== null)
    .map((h) => ({ label: h.symbol, value: Math.round(h.current_value) }))
    .sort((a, b) => b.value - a.value)
}

const sectorSlices = computed(() => (store.detail ? bySector(store.detail.holdings) : []))
const stockSlices = computed(() => (store.detail ? byStock(store.detail.holdings) : []))
const total = computed(() => sectorSlices.value.reduce((sum, s) => sum + s.value, 0))

onMounted(async () => {
  await store.fetchPortfolios()
  if (store.activePortfolioId) await store.fetchDetail(store.activePortfolioId)
})
</script>

<template>
  <div>
    <h1>Diversification</h1>
    <p class="muted">How your current portfolio value is spread across sectors and individual stocks.</p>

    <p v-if="!store.detail" class="muted">Loading…</p>

    <template v-else-if="sectorSlices.length">
      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>By Sector</h3>
          <DiversificationChart :slices="sectorSlices" />
        </div>
        <div class="card">
          <h3>By Stock</h3>
          <DiversificationChart :slices="stockSlices" />
        </div>
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>Sector Allocation</h3>
        <table class="table">
          <thead><tr><th>Sector</th><th>Value</th><th>% of Portfolio</th></tr></thead>
          <tbody>
            <tr v-for="s in sectorSlices" :key="s.label">
              <td>{{ s.label }}</td>
              <td>Rs. {{ formatPrice(s.value) }}</td>
              <td>{{ total > 0 ? ((s.value / total) * 100).toFixed(1) : '0' }}%</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <p v-else class="muted">No open holdings yet — add a buy transaction from Portfolio Overview to see your allocation.</p>
  </div>
</template>
