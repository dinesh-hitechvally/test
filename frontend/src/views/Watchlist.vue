<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { useStocksStore } from '../stores/stocks'
import { formatPrice } from '../utils/format'
import SearchableSelect from '../components/SearchableSelect.vue'
import WatchlistTable from '../components/WatchlistTable.vue'

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
      <WatchlistTable
        v-if="wl.stocks.length"
        :stocks="wl.stocks"
        @set-alert="(stock) => openAlertEditor(wl.id, stock)"
        @remove="(stockId) => removeFromWatchlist(wl.id, stockId)"
      />
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

.picker-row {
  display: flex;
  gap: 10px;
}
</style>
