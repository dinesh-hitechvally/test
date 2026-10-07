<script setup>
import { computed, ref, watch } from 'vue'
import { changeTone, formatPrice, formatNumber } from '../../utils/format'

const SIGNAL_OPTIONS = [
  { value: '', label: 'All signals' },
  { value: 'buy', label: 'Buy' },
  { value: 'hold', label: 'Hold' },
  { value: 'sell', label: 'Sell' },
]

// AI opinions come back as buy/hold/sell too (see AiStockOpinionService's responseSchema).
const AI_OPINION_OPTIONS = [
  { value: '', label: 'All AI opinions' },
  { value: 'buy', label: 'Buy' },
  { value: 'hold', label: 'Hold' },
  { value: 'sell', label: 'Sell' },
]

const props = defineProps({
  stocks: { type: Array, required: true },
  defaultSort: { type: Object, default: () => ({ key: 'symbol', dir: 'asc' }) },
  showTurnoverVolume: { type: Boolean, default: false },
  showAiOpinion: { type: Boolean, default: false },
  // Which columns to draw (keys: symbol, company_name, sector, last_close, high, low, high_52w, low_52w, change_pct, turnover,
  // volume, signal, ai_opinion). null = all the page enabled. The page's Screen Options drive this.
  columns: { type: Array, default: null },
})

// Turnover / volume / AI opinion also need the page to have enabled them.
const enabled = (key) => {
  if (key === 'turnover' || key === 'volume') return props.showTurnoverVolume
  if (key === 'ai_opinion') return props.showAiOpinion
  return true
}
const show = (key) => enabled(key) && (props.columns === null || props.columns.includes(key))
const ALL_KEYS = ['symbol', 'company_name', 'sector', 'last_close', 'high', 'low', 'high_52w', 'low_52w', 'change_pct', 'turnover', 'volume', 'signal', 'ai_opinion']
const visibleCount = computed(() => ALL_KEYS.filter(show).length)

// A hidden column can't stay the sort column (the data for it may not even have been fetched).
watch(
  () => props.columns,
  () => {
    if (!show(sortKey.value)) {
      sortKey.value = 'symbol'
      sortDir.value = 'asc'
    }
  }
)

const search = ref('')
const sectorFilter = ref('')
const signalFilter = ref('')
const aiOpinionFilter = ref('')
// Numeric / range filters — each only applies while its column is shown (hidden columns aren't fetched).
const priceMin = ref('')
const priceMax = ref('')
const changeMode = ref('') // '' | 'up' | 'down' | 'flat'
const changeMin = ref('')
const changeMax = ref('')
const volumeMin = ref('')
const turnoverMin = ref('')

const CHANGE_MODES = [
  { value: '', label: 'All' },
  { value: 'up', label: '▲ Up' },
  { value: 'down', label: '▼ Down' },
  { value: 'flat', label: 'Unchanged' },
]
const sortKey = ref(props.defaultSort.key)
const sortDir = ref(props.defaultSort.dir)
const page = ref(1)
const pageSize = 50

watch(
  () => props.defaultSort,
  (val) => {
    sortKey.value = val.key
    sortDir.value = val.dir
    page.value = 1
  }
)

const sectors = computed(() => {
  const set = new Set(props.stocks.map((s) => s.sector || 'No Sector'))
  return Array.from(set).sort()
})

const sectorOptions = computed(() => [{ value: '', label: 'All sectors' }, ...sectors.value.map((s) => ({ value: s, label: s }))])

function sectorOf(stock) {
  return stock.sector || 'No Sector'
}

function sortValue(stock, key) {
  if (key === 'last_close') return stock.latest_price?.close_price ?? -Infinity
  if (key === 'high') return Number(stock.latest_price?.high_price ?? -Infinity)
  if (key === 'low') return Number(stock.latest_price?.low_price ?? -Infinity)
  if (key === 'high_52w') return stock.high_52w ?? -Infinity
  if (key === 'low_52w') return stock.low_52w ?? -Infinity
  if (key === 'change_pct') return stock.change_pct ?? -Infinity
  if (key === 'turnover') return Number(stock.latest_price?.turnover ?? -Infinity)
  if (key === 'volume') return Number(stock.latest_price?.volume ?? -Infinity)
  if (key === 'signal') return stock.latest_signal?.signal ?? ''
  if (key === 'ai_opinion') return stock.ai_opinion?.verdict ?? ''
  if (key === 'sector') return sectorOf(stock)
  return stock[key] ?? ''
}

function numberOrNull(value) {
  return value === null || value === undefined || value === '' ? null : Number(value)
}
const bound = (value) => numberOrNull(value)

// Which filters are narrowing the list (counted only for columns that are shown).
const activeFilters = computed(() => {
  const on = []
  if (search.value.trim()) on.push('search')
  if (show('sector') && sectorFilter.value) on.push('sector')
  if (show('signal') && signalFilter.value) on.push('signal')
  if (show('ai_opinion') && aiOpinionFilter.value) on.push('ai')
  if (show('last_close') && (priceMin.value !== '' || priceMax.value !== '')) on.push('price')
  if (show('change_pct') && (changeMode.value || changeMin.value !== '' || changeMax.value !== '')) on.push('change')
  if (show('volume') && volumeMin.value !== '') on.push('volume')
  if (show('turnover') && turnoverMin.value !== '') on.push('turnover')
  return on
})

function clearFilters() {
  search.value = ''
  sectorFilter.value = ''
  signalFilter.value = ''
  aiOpinionFilter.value = ''
  priceMin.value = ''
  priceMax.value = ''
  changeMin.value = ''
  changeMax.value = ''
  volumeMin.value = ''
  turnoverMin.value = ''
  changeMode.value = ''
}

// Any filter change starts again from page 1.
watch([search, sectorFilter, signalFilter, aiOpinionFilter, priceMin, priceMax, changeMode, changeMin, changeMax, volumeMin, turnoverMin], () => {
  page.value = 1
})

const filtered = computed(() => {
  let list = props.stocks

  if (search.value.trim()) {
    const term = search.value.trim().toLowerCase()
    list = list.filter((s) => s.symbol.toLowerCase().includes(term) || (s.company_name || '').toLowerCase().includes(term))
  }
  if (sectorFilter.value) {
    list = list.filter((s) => sectorOf(s) === sectorFilter.value)
  }
  if (signalFilter.value) {
    list = list.filter((s) => s.latest_signal?.signal === signalFilter.value)
  }
  if (aiOpinionFilter.value) {
    list = list.filter((s) => s.ai_opinion?.verdict === aiOpinionFilter.value)
  }

  // A blank box means "no limit"; a stock with no value for a filtered field is left out.
  const between = (value, min, max) => {
    if (min === null && max === null) return true
    if (value === null || Number.isNaN(value)) return false
    return (min === null || value >= min) && (max === null || value <= max)
  }
  if (show('last_close')) {
    const min = bound(priceMin.value)
    const max = bound(priceMax.value)
    list = list.filter((s) => between(numberOrNull(s.latest_price?.close_price), min, max))
  }
  if (show('change_pct')) {
    const min = bound(changeMin.value)
    const max = bound(changeMax.value)
    list = list.filter((s) => between(numberOrNull(s.change_pct), min, max))
    if (changeMode.value === 'up') list = list.filter((s) => s.change_pct > 0)
    if (changeMode.value === 'down') list = list.filter((s) => s.change_pct < 0)
    if (changeMode.value === 'flat') list = list.filter((s) => s.change_pct === 0)
  }
  if (show('volume')) {
    const min = bound(volumeMin.value)
    list = list.filter((s) => between(numberOrNull(s.latest_price?.volume), min, null))
  }
  if (show('turnover')) {
    const min = bound(turnoverMin.value)
    list = list.filter((s) => between(numberOrNull(s.latest_price?.turnover), min, null))
  }

  return [...list].sort((a, b) => {
    const av = sortValue(a, sortKey.value)
    const bv = sortValue(b, sortKey.value)
    if (av < bv) return sortDir.value === 'asc' ? -1 : 1
    if (av > bv) return sortDir.value === 'asc' ? 1 : -1
    return 0
  })
})

const pageCount = computed(() => Math.max(1, Math.ceil(filtered.value.length / pageSize)))

const paged = computed(() => {
  const start = (page.value - 1) * pageSize
  return filtered.value.slice(start, start + pageSize)
})

function toggleSort(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = 'asc'
  }
}

function sortIndicator(key) {
  if (sortKey.value !== key) return ''
  return sortDir.value === 'asc' ? '▲' : '▼'
}

// What <SortableTh> needs — this table sorts by hand (custom sort values).
const table = { toggleSort, sortIndicator }

function formatInt(value) {
  if (value === null || value === undefined) return '—'
  return formatNumber(value)
}
</script>

<template>
  <div>
    <div class="filters">
      <div class="filter-row">
        <input v-model="search" class="input search" placeholder="Search symbol or company…" />
        <SearchableSelect v-if="show('sector')" v-model="sectorFilter" :options="sectorOptions" style="max-width: 200px" />
        <SearchableSelect v-if="show('signal')" v-model="signalFilter" :options="SIGNAL_OPTIONS" style="max-width: 180px" />
        <SearchableSelect v-if="show('ai_opinion')" v-model="aiOpinionFilter" :options="AI_OPINION_OPTIONS" style="max-width: 180px" />
      </div>

      <div v-if="show('last_close') || show('change_pct') || show('volume') || show('turnover')" class="filter-row">
        <div v-if="show('last_close')" class="field">
          <label>Price (Rs.)</label>
          <div class="range">
            <input v-model="priceMin" type="number" min="0" step="any" class="input" placeholder="Min" />
            <span class="muted">–</span>
            <input v-model="priceMax" type="number" min="0" step="any" class="input" placeholder="Max" />
          </div>
        </div>

        <div v-if="show('change_pct')" class="field">
          <label>Today's change</label>
          <div class="range">
            <div class="chips" role="group" aria-label="Direction of today's change">
              <button v-for="m in CHANGE_MODES" :key="m.value" type="button" class="chip" :class="{ on: changeMode === m.value }" @click="changeMode = m.value">
                {{ m.label }}
              </button>
            </div>
            <input v-model="changeMin" type="number" step="any" class="input" placeholder="Min %" />
            <span class="muted">–</span>
            <input v-model="changeMax" type="number" step="any" class="input" placeholder="Max %" />
          </div>
        </div>

        <div v-if="show('volume')" class="field">
          <label>Min volume</label>
          <input v-model="volumeMin" type="number" min="0" step="any" class="input" placeholder="e.g. 10000" />
        </div>

        <div v-if="show('turnover')" class="field">
          <label>Min turnover (Rs.)</label>
          <input v-model="turnoverMin" type="number" min="0" step="any" class="input" placeholder="e.g. 1000000" />
        </div>
      </div>

      <div class="filter-foot">
        <button v-if="activeFilters.length" type="button" class="clear" @click="clearFilters">Clear {{ activeFilters.length }} filter{{ activeFilters.length === 1 ? '' : 's' }}</button>
        <span class="muted result-count">{{ filtered.length }} of {{ stocks.length }} stocks</span>
      </div>
    </div>

    <table v-align-numbers class="table">
      <thead>
        <tr>
          <SortableTh v-if="show('symbol')" :table="table" column="symbol">Symbol</SortableTh>
          <SortableTh v-if="show('company_name')" :table="table" column="company_name">Company</SortableTh>
          <SortableTh v-if="show('sector')" :table="table" column="sector">Sector</SortableTh>
          <SortableTh v-if="show('last_close')" :table="table" column="last_close">Last Close</SortableTh>
          <SortableTh v-if="show('high')" :table="table" column="high">High</SortableTh>
          <SortableTh v-if="show('low')" :table="table" column="low">Low</SortableTh>
          <SortableTh v-if="show('high_52w')" :table="table" column="high_52w">52W High</SortableTh>
          <SortableTh v-if="show('low_52w')" :table="table" column="low_52w">52W Low</SortableTh>
          <SortableTh v-if="show('change_pct')" :table="table" column="change_pct">% Change</SortableTh>
          <SortableTh v-if="show('turnover')" :table="table" column="turnover">Turnover</SortableTh>
          <SortableTh v-if="show('volume')" :table="table" column="volume">Volume</SortableTh>
          <SortableTh v-if="show('signal')" :table="table" column="signal">Signal</SortableTh>
          <SortableTh v-if="show('ai_opinion')" :table="table" column="ai_opinion">AI Opinion</SortableTh>
        </tr>
      </thead>
      <tbody>
        <tr v-for="stock in paged" :key="stock.id">
          <td v-if="show('symbol')">
            <StockLink :symbol="stock.symbol" />
          </td>
          <td v-if="show('company_name')">{{ stock.company_name || '—' }}</td>
          <td v-if="show('sector')">{{ sectorOf(stock) }}</td>
          <td v-if="show('last_close')">{{ formatPrice(stock.latest_price?.close_price) }}</td>
          <td v-if="show('high')">{{ formatPrice(stock.latest_price?.high_price) }}</td>
          <td v-if="show('low')">{{ formatPrice(stock.latest_price?.low_price) }}</td>
          <td v-if="show('high_52w')">{{ formatPrice(stock.high_52w) }}</td>
          <td v-if="show('low_52w')">{{ formatPrice(stock.low_52w) }}</td>
          <td v-if="show('change_pct')" :class="changeTone(stock.change_pct)">
            {{ stock.change_pct !== null && stock.change_pct !== undefined ? `${stock.change_pct > 0 ? '+' : ''}${stock.change_pct}%` : '—' }}
          </td>
          <td v-if="show('turnover')">{{ formatInt(stock.latest_price?.turnover) }}</td>
          <td v-if="show('volume')">{{ formatInt(stock.latest_price?.volume) }}</td>
          <td v-if="show('signal')">
            <SignalBadge v-if="stock.latest_signal" :signal="stock.latest_signal.signal" />
            <span v-else class="muted">No data</span>
          </td>
          <td v-if="show('ai_opinion')" :title="stock.ai_opinion?.reasoning || ''">
            <span v-if="stock.ai_opinion?.verdict" class="badge" :class="stock.ai_opinion.verdict === 'buy' ? 'buy' : stock.ai_opinion.verdict === 'sell' ? 'sell' : 'hold'">
              {{ stock.ai_opinion.verdict }}
            </span>
            <span v-else class="muted">Not yet</span>
          </td>
        </tr>
        <tr v-if="paged.length === 0">
          <td :colspan="visibleCount" class="muted" style="text-align: center; padding: 24px">No stocks match these filters.</td>
        </tr>
      </tbody>
    </table>

    <Pagination v-model="page" :total-pages="pageCount" />
  </div>
</template>

<style scoped>
.filters {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 16px;
  padding: 14px 16px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
}

.filter-row {
  display: flex;
  gap: 10px 20px;
  align-items: flex-end;
  flex-wrap: wrap;
}

.search {
  max-width: 280px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.field label {
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.range {
  display: flex;
  gap: 6px;
  align-items: center;
  flex-wrap: wrap;
}

.range .input,
.field > .input {
  width: 110px;
}

.chips {
  display: inline-flex;
  border: 1px solid var(--border);
  border-radius: 8px;
  overflow: hidden;
}

.chip {
  border: 0;
  border-right: 1px solid var(--border);
  background: transparent;
  color: var(--text-muted);
  padding: 6px 10px;
  font: inherit;
  font-size: 0.82rem;
  cursor: pointer;
}

.chip:last-child {
  border-right: 0;
}

.chip.on {
  background: var(--primary-soft);
  color: var(--primary);
  font-weight: 600;
}

.filter-foot {
  display: flex;
  align-items: center;
  gap: 14px;
}

.clear {
  border: 0;
  background: none;
  color: var(--primary);
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
  padding: 0;
}

.result-count {
  margin-left: auto;
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
</style>
