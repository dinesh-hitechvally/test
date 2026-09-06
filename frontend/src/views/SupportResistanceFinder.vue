<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
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
const { sorted, toggleSort, sortIndicator } = useSortableTable(filtered, {
  valueGetters: {
    close: (r) => (r.current_price !== null ? Number(r.current_price) : null),
  },
})

async function load() {
  loading.value = true
  const { data } = await client.get('/market/52-week')
  rows.value = data
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Support &amp; Resistance Finder</h1>
    <p class="muted">
      A market-wide scan using each stock's 52-week high/low as a fast proxy for resistance/support — the same
      swing-level detection used on a single stock's Technical Analysis report isn't run across all {{ rows.length }}
      stocks live (too slow to do on every page load), so this uses the cheaper, already-tracked 52-week range instead.
    </p>

    <div class="filters">
      <button class="btn-secondary btn" :class="{ active: mode === 'resistance' }" @click="mode = 'resistance'">Testing Resistance (near 52W High)</button>
      <button class="btn-secondary btn" :class="{ active: mode === 'support' }" @click="mode = 'support'">Testing Support (near 52W Low)</button>
    </div>

    <p v-if="loading" class="muted" style="margin-top: 12px">Loading…</p>

    <div v-else class="card" style="margin-top: 12px">
      <table class="table">
        <thead>
          <tr>
            <th class="sortable" @click="toggleSort('symbol')">Symbol {{ sortIndicator('symbol') }}</th>
            <th class="sortable" @click="toggleSort('company_name')">Company {{ sortIndicator('company_name') }}</th>
            <th class="sortable" @click="toggleSort('close')">Price {{ sortIndicator('close') }}</th>
            <th class="sortable" @click="toggleSort('high_52w')">52W High {{ sortIndicator('high_52w') }}</th>
            <th class="sortable" @click="toggleSort('low_52w')">52W Low {{ sortIndicator('low_52w') }}</th>
            <th class="sortable" @click="toggleSort('pct_from_high')">% From High {{ sortIndicator('pct_from_high') }}</th>
            <th class="sortable" @click="toggleSort('pct_from_low')">% From Low {{ sortIndicator('pct_from_low') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in sorted" :key="r.stock_id">
            <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: r.symbol } }">{{ r.symbol }}</RouterLink></td>
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
