<script setup>
import { computed, onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import { changeTone, formatPrice } from '../utils/format'

const rows = ref([])
const loading = ref(true)
const sortKey = ref('pct_from_high')
const sortDir = ref('asc')
const page = ref(1)
const pageSize = 50

async function load() {
  loading.value = true
  rows.value = await marketApi.fiftyTwoWeek()
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

// What <SortableTh> needs — this page sorts by hand (it also resets the page).
const table = { toggleSort, sortIndicator }

const sorted = computed(() =>
  [...rows.value].sort((a, b) => {
    const av = a[sortKey.value] ?? -Infinity
    const bv = b[sortKey.value] ?? -Infinity
    if (av < bv) return sortDir.value === 'asc' ? -1 : 1
    if (av > bv) return sortDir.value === 'asc' ? 1 : -1
    return 0
  })
)

const pageCount = computed(() => Math.max(1, Math.ceil(sorted.value.length / pageSize)))

const paged = computed(() => {
  const start = (page.value - 1) * pageSize
  return sorted.value.slice(start, start + pageSize)
})

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="52 Week High/Low">How far each stock's current price sits from its highest and lowest close over the last 365 days.</PageHeader>

    <LoadingState v-if="loading" />
    <table v-align-numbers v-else class="table">
      <thead>
        <tr>
          <SortableTh :table="table" column="symbol">Symbol</SortableTh>
          <SortableTh :table="table" column="current_price">Current Price</SortableTh>
          <SortableTh :table="table" column="high_52w">52W High</SortableTh>
          <SortableTh :table="table" column="low_52w">52W Low</SortableTh>
          <SortableTh :table="table" column="pct_from_high">% From High</SortableTh>
          <SortableTh :table="table" column="pct_from_low">% From Low</SortableTh>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in paged" :key="row.stock_id">
          <td><StockLink :symbol="row.symbol" /></td>
          <td>{{ formatPrice(row.current_price) }}</td>
          <td>{{ formatPrice(row.high_52w) }}</td>
          <td>{{ formatPrice(row.low_52w) }}</td>
          <td :class="changeTone(row.pct_from_high)">{{ row.pct_from_high }}%</td>
          <td :class="changeTone(row.pct_from_low)">{{ row.pct_from_low }}%</td>
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
