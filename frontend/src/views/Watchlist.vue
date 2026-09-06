<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { useStocksStore } from '../stores/stocks'
import { formatPrice } from '../utils/format'
import SearchableSelect from '../components/SearchableSelect.vue'

const stocksStore = useStocksStore()
const watchlists = ref([])
const selectedStockId = ref('')
const selectedWatchlistId = ref('')

const watchlistOptions = computed(() => watchlists.value.map((wl) => ({ value: wl.id, label: wl.name })))
const stockOptions = computed(() => stocksStore.stocks.map((s) => ({ value: s.id, label: `${s.symbol} — ${s.company_name}` })))
const ALERT_DIRECTION_OPTIONS = [
  { value: 'above', label: 'Alert when above' },
  { value: 'below', label: 'Alert when below' },
]

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

const editingAlertFor = ref(null) // { watchlistId, stock }
const alertPrice = ref('')
const alertDirection = ref('above')
const alertSaving = ref(false)

function openAlertEditor(watchlistId, stock) {
  editingAlertFor.value = { watchlistId, stock }
  alertPrice.value = stock.pivot?.alert_price ?? ''
  alertDirection.value = stock.pivot?.alert_direction ?? 'above'
}

function closeAlertEditor() {
  editingAlertFor.value = null
}

async function saveAlert() {
  if (!editingAlertFor.value) return
  alertSaving.value = true
  try {
    const { watchlistId, stock } = editingAlertFor.value
    await client.put(`/watchlists/${watchlistId}/items/${stock.id}/alert`, {
      alert_price: alertPrice.value || null,
      alert_direction: alertPrice.value ? alertDirection.value : null,
    })
    closeAlertEditor()
    await load()
  } finally {
    alertSaving.value = false
  }
}

async function clearAlert() {
  alertPrice.value = ''
  await saveAlert()
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
        <SearchableSelect v-model="selectedWatchlistId" :options="watchlistOptions" />
        <SearchableSelect v-model="selectedStockId" :options="stockOptions" placeholder="Select a stock…" />
        <button class="btn" @click="addToWatchlist">Add</button>
      </div>
    </div>

    <div v-for="wl in watchlists" :key="wl.id" class="card" style="margin-bottom: 16px">
      <h3>{{ wl.name }}</h3>
      <table class="table" v-if="wl.stocks.length">
        <thead>
          <tr><th>Symbol</th><th>Company</th><th>Sector</th><th>Last Close</th><th>% Change</th><th>Signal</th><th>Price Alert</th><th></th></tr>
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
            <td class="muted">
              <span v-if="stock.pivot?.alert_price">Alert when {{ stock.pivot.alert_direction }} Rs. {{ formatPrice(stock.pivot.alert_price) }}</span>
              <span v-else>Not set</span>
            </td>
            <td class="row-actions">
              <button class="btn-secondary btn" @click="openAlertEditor(wl.id, stock)">Set Alert</button>
              <button class="btn-secondary btn" @click="removeFromWatchlist(wl.id, stock.id)">Remove</button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">No stocks yet.</p>
    </div>

    <div v-if="editingAlertFor" class="card form-stack" style="margin-top: 16px">
      <h3>Price Alert — {{ editingAlertFor.stock.symbol }}</h3>
      <p class="muted">Current price Rs. {{ formatPrice(editingAlertFor.stock.latest_price?.close_price) }}.</p>
      <div class="picker-row">
        <SearchableSelect v-model="alertDirection" :options="ALERT_DIRECTION_OPTIONS" style="max-width: 160px" />
        <input v-model="alertPrice" type="number" min="0" step="0.01" class="input" placeholder="Price (Rs.)" style="max-width: 160px" />
      </div>
      <div class="picker-row">
        <button class="btn" :disabled="alertSaving" @click="saveAlert">{{ alertSaving ? 'Saving…' : 'Save' }}</button>
        <button class="btn-secondary btn" :disabled="alertSaving" @click="clearAlert">Clear Alert</button>
        <button class="btn-secondary btn" :disabled="alertSaving" @click="closeAlertEditor">Cancel</button>
      </div>
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

.row-actions {
  display: flex;
  gap: 8px;
}

.picker-row {
  display: flex;
  gap: 10px;
}
</style>
