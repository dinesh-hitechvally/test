<script setup>
import { computed, onMounted, ref } from 'vue'
import client from '../api/client'
import { useStocksStore } from '../stores/stocks'
import ComparisonChart from '../components/ComparisonChart.vue'
import SearchableSelect from '../components/SearchableSelect.vue'

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
  const { data } = await client.get('/indices')
  indices.value = data
  if (data.length) selectedIndex.value = data[0].index_name
}

async function compare() {
  if (!selectedStock.value || !selectedIndex.value) return

  loading.value = true
  hasCompared.value = true
  try {
    const [pricesRes, indexRes] = await Promise.all([
      client.get(`/stocks/${selectedStock.value}/prices`, { params: { days: 180 } }),
      client.get('/indices', { params: { days: 180 } }),
    ])

    const indexData = indexRes.data.find((i) => i.index_name === selectedIndex.value)
    const stockPrices = pricesRes.data

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
    <h1>Stock vs Index</h1>
    <p class="muted">
      Compares a stock's price performance against a NEPSE index, normalized to % change from the start of the
      range. Index history only started accumulating recently — the further back you look, the shorter the index
      line will be until more days build up. Sector-vs-index isn't available yet — there's no computed sector price
      series to compare against, only individual stocks and the official indices.
    </p>

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
    <p v-else-if="!hasCompared" class="muted" style="margin-top: 16px">Pick a stock and an index, then compare.</p>
  </div>
</template>

<style scoped>
.picker-row {
  display: flex;
  gap: 10px;
}
</style>
