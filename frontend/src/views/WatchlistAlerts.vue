<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const watchlists = ref([])
const loading = ref(true)

const alertRows = computed(() => {
  const rows = []
  watchlists.value.forEach((wl) => {
    wl.stocks.forEach((stock) => {
      if (!stock.pivot?.alert_price) return
      const current = stock.latest_price?.close_price !== undefined ? Number(stock.latest_price.close_price) : null
      const target = Number(stock.pivot.alert_price)
      const triggered = current !== null && (stock.pivot.alert_direction === 'above' ? current >= target : current <= target)
      rows.push({ watchlist: wl.name, stock, current, target, direction: stock.pivot.alert_direction, triggered })
    })
  })
  return rows
})

const { sorted, toggleSort, sortIndicator } = useSortableTable(alertRows, {
  valueGetters: {
    symbol: (r) => r.stock.symbol,
    status: (r) => (r.triggered ? 1 : 0),
  },
})

async function load() {
  loading.value = true
  const { data } = await client.get('/watchlists')
  watchlists.value = data
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Price Alerts</h1>
    <p class="muted">
      Every price alert set across your watchlists (separate from the portfolio stop-loss/target alerts, which live
      on the Portfolio page). Manage individual alerts from <RouterLink :to="{ name: 'watchlist' }">Watchlist</RouterLink>.
    </p>

    <p v-if="loading" class="muted">Loading…</p>

    <div v-else class="card">
      <table class="table" v-if="alertRows.length">
        <thead>
          <tr>
            <th class="sortable" @click="toggleSort('symbol')">Symbol {{ sortIndicator('symbol') }}</th>
            <th class="sortable" @click="toggleSort('watchlist')">Watchlist {{ sortIndicator('watchlist') }}</th>
            <th class="sortable" @click="toggleSort('current')">Current Price {{ sortIndicator('current') }}</th>
            <th class="sortable" @click="toggleSort('target')">Alert Condition {{ sortIndicator('target') }}</th>
            <th class="sortable" @click="toggleSort('status')">Status {{ sortIndicator('status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in sorted" :key="`${row.watchlist}-${row.stock.id}`">
            <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: row.stock.symbol } }">{{ row.stock.symbol }}</RouterLink></td>
            <td class="muted">{{ row.watchlist }}</td>
            <td>{{ row.current !== null ? `Rs. ${formatPrice(row.current)}` : '—' }}</td>
            <td>{{ row.direction === 'above' ? 'Above' : 'Below' }} Rs. {{ formatPrice(row.target) }}</td>
            <td>
              <span class="badge" :class="row.triggered ? (row.direction === 'above' ? 'buy' : 'sell') : 'hold'">
                {{ row.triggered ? 'Triggered' : 'Watching' }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">No price alerts set yet — add one from any stock on your Watchlist.</p>
    </div>
  </div>
</template>
