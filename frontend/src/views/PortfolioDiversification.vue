<script setup>
import { computed, onMounted } from 'vue'
import { usePortfolioStore } from '../stores/portfolio'
import { formatPrice } from '../utils/format'
import DiversificationChart from '../components/charts/DiversificationChart.vue'
import { useSortableTable } from '../composables/useSortableTable'

const store = usePortfolioStore()

function bySector(holdings) {
  const totals = {}
  holdings.forEach((h) => {
    if (h.current_value === null) return
    const key = h.sector || 'No Sector'
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

const sectorTable = useSortableTable(sectorSlices, {
  defaultKey: 'value',
  defaultDir: 'desc',
  valueGetters: {
    pct: (s) => (total.value > 0 ? (s.value / total.value) * 100 : 0),
  },
})
const { sorted: sortedSectorSlices } = sectorTable

onMounted(async () => {
  await store.fetchPortfolios()
  if (store.activePortfolioId) await store.fetchDetail(store.activePortfolioId)
})
</script>

<template>
  <div>
    <LoadingState v-if="!store.detail" />

    <template v-else-if="sectorSlices.length">
      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <Card title="By Sector">
          <DiversificationChart :slices="sectorSlices" />
        </Card>
        <Card title="By Stock">
          <DiversificationChart :slices="stockSlices" />
        </Card>
      </div>

      <Card title="Sector Allocation" style="margin-top: 16px">
        <table v-align-numbers class="table">
          <thead>
            <tr>
              <SortableTh :table="sectorTable" column="label">Sector</SortableTh>
              <SortableTh :table="sectorTable" column="value">Value</SortableTh>
              <SortableTh :table="sectorTable" column="pct">% of Portfolio</SortableTh>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in sortedSectorSlices" :key="s.label">
              <td>{{ s.label }}</td>
              <td>Rs. {{ formatPrice(s.value) }}</td>
              <td>{{ total > 0 ? ((s.value / total) * 100).toFixed(1) : '0' }}%</td>
            </tr>
          </tbody>
        </table>
      </Card>
    </template>

    <EmptyState v-else>No open holdings yet — add a buy transaction from Portfolio Overview to see your allocation.</EmptyState>
  </div>
</template>
