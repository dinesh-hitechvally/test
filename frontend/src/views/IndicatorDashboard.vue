<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'
import Pagination from '../components/Pagination.vue'

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

function rsiTone(rsi) {
  if (rsi === null) return ''
  return rsi >= 70 ? 'negative' : rsi <= 30 ? 'positive' : ''
}

const pageCount = computed(() => Math.max(1, Math.ceil(sorted.value.length / pageSize)))
const paged = computed(() => sorted.value.slice((page.value - 1) * pageSize, page.value * pageSize))

async function load() {
  loading.value = true
  const { data } = await client.get('/market/screener')
  stocks.value = data
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Indicator Dashboard</h1>
    <p class="muted">RSI, MACD, and volume for every tracked stock in one sortable table — click a column to sort.</p>

    <p v-if="loading" class="muted">Loading…</p>

    <template v-else>
      <table class="table">
        <thead>
          <tr>
            <th class="sortable" @click="toggleSort('symbol')">Symbol {{ sortIndicator('symbol') }}</th>
            <th class="sortable" @click="toggleSort('rsi_14')">RSI (14) {{ sortIndicator('rsi_14') }}</th>
            <th class="sortable" @click="toggleSort('macd')">MACD {{ sortIndicator('macd') }}</th>
            <th class="sortable" @click="toggleSort('macd_signal')">Signal Line {{ sortIndicator('macd_signal') }}</th>
            <th class="sortable" @click="toggleSort('macd_histogram')">Histogram {{ sortIndicator('macd_histogram') }}</th>
            <th class="sortable" @click="toggleSort('volume')">Volume {{ sortIndicator('volume') }}</th>
            <th>Signal</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in paged" :key="s.id">
            <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
            <td :class="rsiTone(ind(s, 'rsi_14'))">{{ ind(s, 'rsi_14') !== null ? ind(s, 'rsi_14').toFixed(1) : '—' }}</td>
            <td>{{ ind(s, 'macd') !== null ? ind(s, 'macd').toFixed(3) : '—' }}</td>
            <td>{{ ind(s, 'macd_signal') !== null ? ind(s, 'macd_signal').toFixed(3) : '—' }}</td>
            <td>{{ ind(s, 'macd_histogram') !== null ? ind(s, 'macd_histogram').toFixed(3) : '—' }}</td>
            <td>{{ s.latest_price?.volume?.toLocaleString() ?? '—' }}</td>
            <td>
              <span v-if="s.latest_signal" class="badge" :class="s.latest_signal.signal">{{ s.latest_signal.signal.replace('_', ' ') }}</span>
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
