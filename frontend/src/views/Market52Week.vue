<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'
import Pagination from '../components/Pagination.vue'

const rows = ref([])
const loading = ref(true)
const sortKey = ref('pct_from_high')
const sortDir = ref('asc')
const page = ref(1)
const pageSize = 50

async function load() {
  loading.value = true
  const { data } = await client.get('/market/52-week')
  rows.value = data
  loading.value = false
}

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

const sorted = computed(() =>
  [...rows.value].sort((a, b) => {
    const av = a[sortKey.value] ?? -Infinity
    const bv = b[sortKey.value] ?? -Infinity
    if (av < bv) return sortDir.value === 'asc' ? -1 : 1
    if (av > bv) return sortDir.value === 'asc' ? 1 : -1
    return 0
  })
)

function tone(pct) {
  if (pct === null || pct === undefined) return ''
  return pct > 0 ? 'positive' : pct < 0 ? 'negative' : ''
}

const pageCount = computed(() => Math.max(1, Math.ceil(sorted.value.length / pageSize)))

const paged = computed(() => {
  const start = (page.value - 1) * pageSize
  return sorted.value.slice(start, start + pageSize)
})

onMounted(load)
</script>

<template>
  <div>
    <h1>52 Week High/Low</h1>
    <p class="muted">How far each stock's current price sits from its highest and lowest close over the last 365 days.</p>

    <p v-if="loading" class="muted">Loading…</p>
    <table v-else class="table">
      <thead>
        <tr>
          <th class="sortable" @click="toggleSort('symbol')">Symbol {{ sortIndicator('symbol') }}</th>
          <th class="sortable" @click="toggleSort('current_price')">Current Price {{ sortIndicator('current_price') }}</th>
          <th class="sortable" @click="toggleSort('high_52w')">52W High {{ sortIndicator('high_52w') }}</th>
          <th class="sortable" @click="toggleSort('low_52w')">52W Low {{ sortIndicator('low_52w') }}</th>
          <th class="sortable" @click="toggleSort('pct_from_high')">% From High {{ sortIndicator('pct_from_high') }}</th>
          <th class="sortable" @click="toggleSort('pct_from_low')">% From Low {{ sortIndicator('pct_from_low') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in paged" :key="row.stock_id">
          <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: row.symbol } }">{{ row.symbol }}</RouterLink></td>
          <td>{{ formatPrice(row.current_price) }}</td>
          <td>{{ formatPrice(row.high_52w) }}</td>
          <td>{{ formatPrice(row.low_52w) }}</td>
          <td :class="tone(row.pct_from_high)">{{ row.pct_from_high }}%</td>
          <td :class="tone(row.pct_from_low)">{{ row.pct_from_low }}%</td>
        </tr>
        <tr v-if="sorted.length === 0">
          <td colspan="6" class="muted" style="text-align: center; padding: 24px">
            No stocks have 365 days of price history yet.
          </td>
        </tr>
      </tbody>
    </table>

    <Pagination v-model="page" :total-pages="pageCount" />
  </div>
</template>

<style scoped>
.sortable {
  cursor: pointer;
  user-select: none;
  white-space: nowrap;
}

.sortable:hover {
  color: var(--text);
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}

</style>
