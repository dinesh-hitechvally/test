<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'
import SearchableSelect from '../components/SearchableSelect.vue'

const route = useRoute()

const SIGNAL_OPTIONS = [
  { value: '', label: 'Any' },
  { value: 'strong_buy', label: 'Strong Buy' },
  { value: 'buy', label: 'Buy' },
  { value: 'hold', label: 'Hold' },
  { value: 'sell', label: 'Sell' },
  { value: 'strong_sell', label: 'Strong Sell' },
]

const SMA_OPTIONS = [
  { value: '', label: 'Any' },
  { value: 'above', label: 'SMA20 above SMA50' },
  { value: 'below', label: 'SMA20 below SMA50' },
]

const stocks = ref([])
const loading = ref(true)

const signalFilter = ref('')
const sectorFilter = ref('')
const rsiMin = ref('')
const rsiMax = ref('')
const changeMin = ref('')
const changeMax = ref('')
const priceMin = ref('')
const priceMax = ref('')
const smaFilter = ref('')

const sortKey = ref('symbol')
const sortDir = ref('asc')
const page = ref(1)
const pageSize = 50

async function load() {
  loading.value = true
  const { data } = await client.get('/market/screener')
  stocks.value = data
  loading.value = false
}

const sectors = computed(() => {
  const set = new Set(stocks.value.map((s) => s.sector || 'Other'))
  return Array.from(set).sort()
})

const sectorOptions = computed(() => [{ value: '', label: 'Any' }, ...sectors.value.map((s) => ({ value: s, label: s }))])

function sectorOf(stock) {
  return stock.sector || 'Other'
}

const filtered = computed(() => {
  return stocks.value.filter((s) => {
    if (signalFilter.value && s.latest_signal?.signal !== signalFilter.value) return false
    if (sectorFilter.value && sectorOf(s) !== sectorFilter.value) return false

    const rsi = s.latest_indicator?.rsi_14 !== undefined && s.latest_indicator?.rsi_14 !== null ? Number(s.latest_indicator.rsi_14) : null
    if (rsiMin.value !== '' && (rsi === null || rsi < Number(rsiMin.value))) return false
    if (rsiMax.value !== '' && (rsi === null || rsi > Number(rsiMax.value))) return false

    if (changeMin.value !== '' && (s.change_pct === null || s.change_pct === undefined || s.change_pct < Number(changeMin.value))) return false
    if (changeMax.value !== '' && (s.change_pct === null || s.change_pct === undefined || s.change_pct > Number(changeMax.value))) return false

    const price = s.latest_price?.close_price !== undefined ? Number(s.latest_price.close_price) : null
    if (priceMin.value !== '' && (price === null || price < Number(priceMin.value))) return false
    if (priceMax.value !== '' && (price === null || price > Number(priceMax.value))) return false

    if (smaFilter.value) {
      const sma20 = s.latest_indicator?.sma_20 !== undefined && s.latest_indicator?.sma_20 !== null ? Number(s.latest_indicator.sma_20) : null
      const sma50 = s.latest_indicator?.sma_50 !== undefined && s.latest_indicator?.sma_50 !== null ? Number(s.latest_indicator.sma_50) : null
      if (sma20 === null || sma50 === null) return false
      if (smaFilter.value === 'above' && sma20 <= sma50) return false
      if (smaFilter.value === 'below' && sma20 >= sma50) return false
    }

    return true
  })
})

function sortValue(stock, key) {
  if (key === 'last_close') return Number(stock.latest_price?.close_price ?? -Infinity)
  if (key === 'change_pct') return stock.change_pct ?? -Infinity
  if (key === 'rsi') return Number(stock.latest_indicator?.rsi_14 ?? -Infinity)
  if (key === 'signal') return stock.latest_signal?.signal ?? ''
  if (key === 'sector') return sectorOf(stock)
  return stock[key] ?? ''
}

const sorted = computed(() =>
  [...filtered.value].sort((a, b) => {
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

function changeTone(pct) {
  if (pct === null || pct === undefined) return ''
  return pct > 0 ? 'positive' : pct < 0 ? 'negative' : ''
}

const pageCount = computed(() => Math.max(1, Math.ceil(sorted.value.length / pageSize)))

const paged = computed(() => {
  const start = (page.value - 1) * pageSize
  return sorted.value.slice(start, start + pageSize)
})

watch(filtered, () => {
  page.value = 1
})

function resetFilters() {
  signalFilter.value = ''
  sectorFilter.value = ''
  rsiMin.value = ''
  rsiMax.value = ''
  changeMin.value = ''
  changeMax.value = ''
  priceMin.value = ''
  priceMax.value = ''
  smaFilter.value = ''
}

function currentFilters() {
  return {
    signal: signalFilter.value, sector: sectorFilter.value, rsiMin: rsiMin.value, rsiMax: rsiMax.value,
    changeMin: changeMin.value, changeMax: changeMax.value, priceMin: priceMin.value, priceMax: priceMax.value, sma: smaFilter.value,
  }
}

function applyFilters(f) {
  signalFilter.value = f.signal ?? ''
  sectorFilter.value = f.sector ?? ''
  rsiMin.value = f.rsiMin ?? ''
  rsiMax.value = f.rsiMax ?? ''
  changeMin.value = f.changeMin ?? ''
  changeMax.value = f.changeMax ?? ''
  priceMin.value = f.priceMin ?? ''
  priceMax.value = f.priceMax ?? ''
  smaFilter.value = f.sma ?? ''
}

const savingName = ref('')
const showSaveForm = ref(false)
const saveError = ref('')

async function saveScreen() {
  if (!savingName.value.trim()) return
  saveError.value = ''
  try {
    await client.post('/saved-screens', { name: savingName.value.trim(), filters: currentFilters() })
    savingName.value = ''
    showSaveForm.value = false
  } catch (e) {
    saveError.value = e.response?.data?.message || 'Could not save this screen.'
  }
}

async function loadSavedScreen(id) {
  const { data } = await client.get('/saved-screens')
  const found = data.find((s) => s.id === Number(id))
  if (found) applyFilters(found.filters)
}

onMounted(async () => {
  await load()
  if (route.query.load) await loadSavedScreen(route.query.load)
})
</script>

<template>
  <div>
    <h1>Market Screener</h1>

    <div class="card" style="margin-bottom: 20px">
      <h3>Filters</h3>
      <div class="filter-grid">
        <label>
          Signal
          <SearchableSelect v-model="signalFilter" :options="SIGNAL_OPTIONS" />
        </label>
        <label>
          Sector
          <SearchableSelect v-model="sectorFilter" :options="sectorOptions" />
        </label>
        <label>
          RSI (14) min
          <input v-model="rsiMin" type="number" class="input" placeholder="e.g. 0" />
        </label>
        <label>
          RSI (14) max
          <input v-model="rsiMax" type="number" class="input" placeholder="e.g. 30" />
        </label>
        <label>
          % Change min
          <input v-model="changeMin" type="number" class="input" placeholder="e.g. -5" />
        </label>
        <label>
          % Change max
          <input v-model="changeMax" type="number" class="input" placeholder="e.g. 5" />
        </label>
        <label>
          Price min
          <input v-model="priceMin" type="number" class="input" placeholder="Rs." />
        </label>
        <label>
          Price max
          <input v-model="priceMax" type="number" class="input" placeholder="Rs." />
        </label>
        <label>
          SMA20 vs SMA50
          <SearchableSelect v-model="smaFilter" :options="SMA_OPTIONS" />
        </label>
      </div>
      <div class="save-row">
        <button class="btn-secondary btn" @click="resetFilters">Reset Filters</button>
        <button class="btn-secondary btn" @click="showSaveForm = !showSaveForm">Save this screen</button>
        <RouterLink :to="{ name: 'screener-saved' }" class="btn-secondary btn">View Saved Screens</RouterLink>
      </div>
      <div v-if="showSaveForm" class="save-form">
        <input v-model="savingName" class="input" placeholder="Name this screen" style="max-width: 260px" @keyup.enter="saveScreen" />
        <button class="btn" @click="saveScreen">Save</button>
      </div>
      <p v-if="saveError" class="error-text">{{ saveError }}</p>
    </div>

    <p v-if="loading" class="muted">Loading…</p>
    <template v-else>
      <p class="muted">{{ sorted.length }} of {{ stocks.length }} stocks match.</p>
      <table class="table">
        <thead>
          <tr>
            <th class="sortable" @click="toggleSort('symbol')">Symbol {{ sortIndicator('symbol') }}</th>
            <th class="sortable" @click="toggleSort('company_name')">Company {{ sortIndicator('company_name') }}</th>
            <th class="sortable" @click="toggleSort('sector')">Sector {{ sortIndicator('sector') }}</th>
            <th class="sortable" @click="toggleSort('last_close')">Price {{ sortIndicator('last_close') }}</th>
            <th class="sortable" @click="toggleSort('change_pct')">% Change {{ sortIndicator('change_pct') }}</th>
            <th class="sortable" @click="toggleSort('rsi')">RSI (14) {{ sortIndicator('rsi') }}</th>
            <th class="sortable" @click="toggleSort('signal')">Signal {{ sortIndicator('signal') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in paged" :key="s.id">
            <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
            <td>{{ s.company_name || '—' }}</td>
            <td>{{ sectorOf(s) }}</td>
            <td>{{ formatPrice(s.latest_price?.close_price) }}</td>
            <td :class="changeTone(s.change_pct)">
              {{ s.change_pct !== null && s.change_pct !== undefined ? `${s.change_pct > 0 ? '+' : ''}${s.change_pct}%` : '—' }}
            </td>
            <td>{{ s.latest_indicator?.rsi_14 !== undefined && s.latest_indicator?.rsi_14 !== null ? Number(s.latest_indicator.rsi_14).toFixed(1) : '—' }}</td>
            <td>
              <span v-if="s.latest_signal" class="badge" :class="s.latest_signal.signal">{{ s.latest_signal.signal.replace('_', ' ') }}</span>
              <span v-else class="muted">No data</span>
            </td>
          </tr>
          <tr v-if="paged.length === 0">
            <td colspan="7" class="muted" style="text-align: center; padding: 24px">No stocks match these filters.</td>
          </tr>
        </tbody>
      </table>

      <div v-if="pageCount > 1" class="pagination">
        <button class="btn-secondary btn" :disabled="page === 1" @click="page--">Prev</button>
        <span class="muted">Page {{ page }} of {{ pageCount }}</span>
        <button class="btn-secondary btn" :disabled="page === pageCount" @click="page++">Next</button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.filter-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
}

.filter-grid label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 0.8rem;
  color: var(--text-muted);
}

.save-row {
  display: flex;
  gap: 10px;
  margin-top: 12px;
}

.save-form {
  display: flex;
  gap: 10px;
  margin-top: 10px;
}

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

.pagination {
  display: flex;
  align-items: center;
  gap: 14px;
  justify-content: center;
  margin-top: 16px;
}
</style>
