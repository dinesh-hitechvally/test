<script setup>
import { computed, onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import { formatPrice, formatNumber } from '../utils/format'

const stocks = ref([])
const loading = ref(true)
const sortKey = ref('rsi')
const sortDir = ref('asc')
const page = ref(1)
const pageSize = 50

function ind(stock, field) {
  const v = stock.latest_indicator?.[field]
  return v !== undefined && v !== null ? Number(v) : null
}

function sortValue(stock, key) {
  if (key === 'symbol') return stock.symbol
  if (key === 'volume') return stock.latest_price?.volume ?? -1
  return ind(stock, key) ?? -Infinity
}

const sorted = computed(() =>
  [...stocks.value].sort((a, b) => {
    const av = sortValue(a, sortKey.value)
    const bv = sortValue(b, sortKey.value)
    if (av < bv) return sortDir.value === 'asc' ? -1 : 1
    if (av > bv) return sortDir.value === 'asc' ? 1 : -1
    return 0
  })
)

function toggleSort(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = 'asc'
  }
  page.value = 1
}

function sortIndicator(key) {
  if (sortKey.value !== key) return ''
  return sortDir.value === 'asc' ? '▲' : '▼'
}

// What <SortableTh> needs — this page sorts by hand (it also resets the page).
const table = { toggleSort, sortIndicator }

function rsiTone(rsi) {
  if (rsi === null) return ''
  return rsi >= 70 ? 'negative' : rsi <= 30 ? 'positive' : ''
}

const pageCount = computed(() => Math.max(1, Math.ceil(sorted.value.length / pageSize)))
const paged = computed(() => sorted.value.slice((page.value - 1) * pageSize, page.value * pageSize))

async function load() {
  loading.value = true
  try {
    stocks.value = await marketApi.screener()
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <LoadingState v-if="loading" />

    <template v-else>
      <table v-align-numbers class="table">
        <thead>
          <tr>
            <SortableTh :table="table" column="symbol">Symbol</SortableTh>
            <SortableTh :table="table" column="rsi_14">RSI (14)</SortableTh>
            <SortableTh :table="table" column="macd">MACD</SortableTh>
            <SortableTh :table="table" column="macd_signal">Signal Line</SortableTh>
            <SortableTh :table="table" column="macd_histogram">Histogram</SortableTh>
            <SortableTh :table="table" column="volume">Volume</SortableTh>
            <th>Signal</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in paged" :key="s.id">
            <td><StockLink :symbol="s.symbol" /></td>
            <td :class="rsiTone(ind(s, 'rsi_14'))">{{ ind(s, 'rsi_14') !== null ? ind(s, 'rsi_14').toFixed(1) : '—' }}</td>
            <td>{{ ind(s, 'macd') !== null ? ind(s, 'macd').toFixed(3) : '—' }}</td>
            <td>{{ ind(s, 'macd_signal') !== null ? ind(s, 'macd_signal').toFixed(3) : '—' }}</td>
            <td>{{ ind(s, 'macd_histogram') !== null ? ind(s, 'macd_histogram').toFixed(3) : '—' }}</td>
            <td>{{ formatNumber(s.latest_price?.volume) }}</td>
            <td>
              <SignalBadge v-if="s.latest_signal" :signal="s.latest_signal.signal" />
              <span v-else class="muted">No data</span>
            </td>
          </tr>
        </tbody>
      </table>

      <Pagination v-model="page" :total-pages="pageCount" />
    </template>
  </div>
</template>

<style scoped>
.sortable {
  cursor: pointer;
  user-select: none;
  white-space: nowrap;
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}

</style>
