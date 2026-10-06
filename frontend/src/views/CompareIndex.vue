<script setup>
import { computed, onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import * as stocksApi from '../api/stocks'
import { useStocksStore } from '../stores/stocks'
import ComparisonChart from '../components/charts/ComparisonChart.vue'

const stocksStore = useStocksStore()
const indices = ref([])
const stockOptions = computed(() => stocksStore.stocks.map((s) => ({ value: s.symbol, label: `${s.symbol} — ${s.company_name}` })))
const indexOptions = computed(() => indices.value.map((idx) => ({ value: idx.index_name, label: idx.index_name })))
const selectedStock = ref('')
const selectedIndex = ref('')
const loading = ref(false)
const chartLabels = ref([])
const chartSeries = ref([])
const hasCompared = ref(false)

async function loadIndices() {
  const data = await marketApi.indices()
  indices.value = data
  if (data.length) selectedIndex.value = data[0].index_name
}

async function compare() {
  if (!selectedStock.value || !selectedIndex.value) return

  loading.value = true
  hasCompared.value = true
  try {
    const [stockPrices, allIndices] = await Promise.all([
      stocksApi.prices(selectedStock.value, 180),
      marketApi.indices(180),
    ])

    const indexData = allIndices.find((i) => i.index_name === selectedIndex.value)

    const dateSet = new Set()
    stockPrices.forEach((p) => dateSet.add(p.trade_date))
    ;(indexData?.history ?? []).forEach((h) => dateSet.add(h.trade_date))
    const labels = Array.from(dateSet).sort()

    const stockByDate = Object.fromEntries(stockPrices.map((p) => [p.trade_date, Number(p.close_price)]))
    const stockFirstDate = stockPrices[0]?.trade_date
    const stockBase = stockFirstDate ? stockByDate[stockFirstDate] : null

    const indexByDate = Object.fromEntries((indexData?.history ?? []).map((h) => [h.trade_date, h.close]))
    const indexFirstDate = indexData?.history?.[0]?.trade_date
    const indexBase = indexFirstDate ? indexByDate[indexFirstDate] : null

    chartLabels.value = labels
    chartSeries.value = [
      {
        symbol: selectedStock.value,
        data: labels.map((d) => (!stockBase || d < stockFirstDate || stockByDate[d] === undefined ? null : Number((((stockByDate[d] - stockBase) / stockBase) * 100).toFixed(2)))),
      },
      {
        symbol: selectedIndex.value,
        data: labels.map((d) => (!indexBase || d < indexFirstDate || indexByDate[d] === undefined ? null : Number((((indexByDate[d] - indexBase) / indexBase) * 100).toFixed(2)))),
      },
    ]
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (stocksStore.stocks.length === 0) await stocksStore.fetchStocks()
  await loadIndices()
})
</script>

<template>
  <div>
    <div class="card">
      <div class="picker-row">
        <SearchableSelect v-model="selectedStock" :options="stockOptions" style="max-width: 280px" placeholder="Select a stock…" />
        <SearchableSelect v-model="selectedIndex" :options="indexOptions" style="max-width: 220px" />
        <button class="btn" :disabled="!selectedStock || loading" @click="compare">{{ loading ? 'Comparing…' : 'Compare' }}</button>
      </div>
    </div>

    <div class="card" style="margin-top: 16px" v-if="hasCompared && !loading">
      <ComparisonChart :labels="chartLabels" :series="chartSeries" />
    </div>
    <EmptyState v-else-if="!hasCompared" style="margin-top: 16px">Pick a stock and an index, then compare.</EmptyState>
  </div>
</template>

<style scoped>
.picker-row {
  display: flex;
  gap: 10px;
}
</style>
