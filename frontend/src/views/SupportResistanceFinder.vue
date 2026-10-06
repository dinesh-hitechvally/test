<script setup>
import { computed, onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import { formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const rows = ref([])
const loading = ref(true)
const mode = ref('resistance') // testing resistance (near 52w high) or support (near 52w low)

const filtered = computed(() =>
  [...rows.value]
    .filter((r) => (mode.value === 'resistance' ? r.pct_from_high >= -5 : r.pct_from_low <= 5))
    .sort((a, b) => (mode.value === 'resistance' ? b.pct_from_high - a.pct_from_high : a.pct_from_low - b.pct_from_low))
)

// No default sort key — starts showing `filtered`'s own mode-based ordering
// (closest to the level being tested first) until a column header is clicked.
const table = useSortableTable(filtered, {
  valueGetters: {
    close: (r) => (r.current_price !== null ? Number(r.current_price) : null),
  },
})
const { sorted } = table

async function load() {
  loading.value = true
  rows.value = await marketApi.fiftyTwoWeek()
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <p class="muted">
      A market-wide scan using each stock's 52-week high/low as a fast proxy for resistance/support — the same
      swing-level detection used on a single stock's Technical Analysis report isn't run across all {{ rows.length }}
      stocks live (too slow to do on every page load), so this uses the cheaper, already-tracked 52-week range instead.
    </p>

    <div class="filters">
      <button class="btn-secondary btn" :class="{ active: mode === 'resistance' }" @click="mode = 'resistance'">Testing Resistance (near 52W High)</button>
      <button class="btn-secondary btn" :class="{ active: mode === 'support' }" @click="mode = 'support'">Testing Support (near 52W Low)</button>
    </div>

    <LoadingState v-if="loading" style="margin-top: 12px" />

    <div v-else class="card" style="margin-top: 12px">
      <table v-align-numbers class="table">
        <thead>
          <tr>
            <SortableTh :table="table" column="symbol">Symbol</SortableTh>
            <SortableTh :table="table" column="company_name">Company</SortableTh>
            <SortableTh :table="table" column="close">Price</SortableTh>
            <SortableTh :table="table" column="high_52w">52W High</SortableTh>
            <SortableTh :table="table" column="low_52w">52W Low</SortableTh>
            <SortableTh :table="table" column="pct_from_high">% From High</SortableTh>
            <SortableTh :table="table" column="pct_from_low">% From Low</SortableTh>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in sorted" :key="r.stock_id">
            <td><StockLink :symbol="r.symbol" /></td>
            <td class="muted">{{ r.company_name }}</td>
            <td>{{ formatPrice(r.current_price) }}</td>
            <td>{{ formatPrice(r.high_52w) }}</td>
            <td>{{ formatPrice(r.low_52w) }}</td>
            <td>{{ r.pct_from_high }}%</td>
            <td>{{ r.pct_from_low }}%</td>
          </tr>
          <tr v-if="filtered.length === 0">
            <td colspan="7" class="muted" style="text-align: center; padding: 24px">No stocks currently testing this level.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.filters {
  display: flex;
  gap: 8px;
}

.filters .active {
  background: #1e293b;
  color: #fff;
  border-color: #1e293b;
}
</style>
