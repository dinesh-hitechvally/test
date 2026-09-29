<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import * as watchlistsApi from '../api/watchlists'
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

const alertsTable = useSortableTable(alertRows, {
  valueGetters: {
    symbol: (r) => r.stock.symbol,
    status: (r) => (r.triggered ? 1 : 0),
  },
})
const { sorted } = alertsTable

async function load() {
  loading.value = true
  watchlists.value = await watchlistsApi.list()
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Price Alerts">
      Every price alert set across your watchlists (separate from the portfolio stop-loss/target alerts, which live
      on the Portfolio page). Manage individual alerts from <RouterLink :to="{ name: 'watchlist' }">Watchlist</RouterLink>.
    </PageHeader>

    <LoadingState v-if="loading" />

    <Card v-else>
      <table v-align-numbers class="table" v-if="alertRows.length">
        <thead>
          <tr>
            <SortableTh :table="alertsTable" column="symbol">Symbol</SortableTh>
            <SortableTh :table="alertsTable" column="watchlist">Watchlist</SortableTh>
            <SortableTh :table="alertsTable" column="current">Current Price</SortableTh>
            <SortableTh :table="alertsTable" column="target">Alert Condition</SortableTh>
            <SortableTh :table="alertsTable" column="status">Status</SortableTh>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in sorted" :key="`${row.watchlist}-${row.stock.id}`">
            <td><StockLink :symbol="row.stock.symbol" /></td>
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
      <EmptyState v-else>No price alerts set yet — add one from any stock on your Watchlist.</EmptyState>
    </Card>
  </div>
</template>
