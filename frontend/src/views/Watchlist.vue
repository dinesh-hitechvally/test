<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { useStocksStore } from '../stores/stocks'
import { formatPrice } from '../utils/format'

const stocksStore = useStocksStore()
const watchlists = ref([])
const selectedStockId = ref('')
const selectedWatchlistId = ref('')

async function load() {
  const [wlRes] = await Promise.all([client.get('/watchlists'), stocksStore.stocks.length ? Promise.resolve() : stocksStore.fetchStocks()])
  watchlists.value = wlRes.data
  if (watchlists.value.length && !selectedWatchlistId.value) {
    selectedWatchlistId.value = watchlists.value[0].id
  }
}

async function addToWatchlist() {
  if (!selectedStockId.value || !selectedWatchlistId.value) return
  await client.post(`/watchlists/${selectedWatchlistId.value}/items`, { stock_id: selectedStockId.value })
  selectedStockId.value = ''
  await load()
}

async function removeFromWatchlist(watchlistId, stockId) {
  await client.delete(`/watchlists/${watchlistId}/items/${stockId}`)
  await load()
}

function formatSignal(label) {
  return label.replace('_', ' ')
}

function changeTone(pct) {
  if (pct === null || pct === undefined) return ''
  return pct > 0 ? 'positive' : pct < 0 ? 'negative' : ''
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Watchlist</h1>

    <div class="card" style="margin-bottom: 20px">
      <h3>Add a stock</h3>
      <div class="add-row">
        <select v-model="selectedWatchlistId" class="input">
          <option v-for="wl in watchlists" :key="wl.id" :value="wl.id">{{ wl.name }}</option>
        </select>
        <select v-model="selectedStockId" class="input">
          <option value="" disabled>Select a stock…</option>
          <option v-for="s in stocksStore.stocks" :key="s.id" :value="s.id">{{ s.symbol }} — {{ s.company_name }}</option>
        </select>
        <button class="btn" @click="addToWatchlist">Add</button>
      </div>
    </div>

    <div v-for="wl in watchlists" :key="wl.id" class="card" style="margin-bottom: 16px">
      <h3>{{ wl.name }}</h3>
      <table class="table" v-if="wl.stocks.length">
        <thead>
          <tr><th>Symbol</th><th>Company</th><th>Sector</th><th>Last Close</th><th>% Change</th><th>Signal</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="stock in wl.stocks" :key="stock.id">
            <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: stock.symbol } }">{{ stock.symbol }}</RouterLink></td>
            <td>{{ stock.company_name || '—' }}</td>
            <td>{{ stock.sector || 'Other' }}</td>
            <td>{{ formatPrice(stock.latest_price?.close_price) }}</td>
            <td :class="changeTone(stock.change_pct)">
              {{ stock.change_pct !== null && stock.change_pct !== undefined ? `${stock.change_pct > 0 ? '+' : ''}${stock.change_pct}%` : '—' }}
            </td>
            <td>
              <span v-if="stock.latest_signal" class="badge" :class="stock.latest_signal.signal">
                {{ formatSignal(stock.latest_signal.signal) }}
              </span>
              <span v-else class="muted">No data</span>
            </td>
            <td><button class="btn-secondary btn" @click="removeFromWatchlist(wl.id, stock.id)">Remove</button></td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">No stocks yet.</p>
    </div>
  </div>
</template>

<style scoped>
.add-row {
  display: flex;
  gap: 10px;
  align-items: center;
}

.add-row .input {
  width: auto;
  flex: 1;
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}
</style>
