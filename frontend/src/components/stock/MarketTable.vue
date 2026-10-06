<script setup>
import { computed, ref, watch } from 'vue'
import { changeTone, formatPrice } from '../../utils/format'

const SIGNAL_OPTIONS = [
  { value: '', label: 'All signals' },
  { value: 'strong_buy', label: 'Strong Buy' },
  { value: 'buy', label: 'Buy' },
  { value: 'hold', label: 'Hold' },
  { value: 'sell', label: 'Sell' },
  { value: 'strong_sell', label: 'Strong Sell' },
]

// AI opinions only ever come back as buy/hold/sell (see
// AiStockOpinionService's responseSchema) — no strong_buy/strong_sell,
// unlike the rule-based signal above.
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
  // Which columns to draw (keys: symbol, company_name, sector, last_close, high, low, change_pct, turnover,
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
const ALL_KEYS = ['symbol', 'company_name', 'sector', 'last_close', 'high', 'low', 'change_pct', 'turnover', 'volume', 'signal', 'ai_opinion']
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
  const set = new Set(props.stocks.map((s) => s.sector || 'Other'))
  return Array.from(set).sort()
})

const sectorOptions = computed(() => [{ value: '', label: 'All sectors' }, ...sectors.value.map((s) => ({ value: s, label: s }))])

function sectorOf(stock) {
  return stock.sector || 'Other'
}

function sortValue(stock, key) {
  if (key === 'last_close') return stock.latest_price?.close_price ?? -Infinity
  if (key === 'high') return Number(stock.latest_price?.high_price ?? -Infinity)
  if (key === 'low') return Number(stock.latest_price?.low_price ?? -Infinity)
  if (key === 'change_pct') return stock.change_pct ?? -Infinity
  if (key === 'turnover') return Number(stock.latest_price?.turnover ?? -Infinity)
  if (key === 'volume') return Number(stock.latest_price?.volume ?? -Infinity)
  if (key === 'signal') return stock.latest_signal?.signal ?? ''
  if (key === 'ai_opinion') return stock.ai_opinion?.verdict ?? ''
  if (key === 'sector') return sectorOf(stock)
  return stock[key] ?? ''
}

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

function resetToFirstPage() {
  page.value = 1
}

function formatInt(value) {
  if (value === null || value === undefined) return '—'
  return Number(value).toLocaleString()
}
</script>

<template>
  <div>
    <div class="filter-bar">
      <input v-model="search" class="input" style="max-width: 280px" placeholder="Search symbol or company…" @input="resetToFirstPage" />
      <SearchableSelect v-if="show('sector')" v-model="sectorFilter" :options="sectorOptions" style="max-width: 200px" @change="resetToFirstPage" />
      <SearchableSelect v-if="show('signal')" v-model="signalFilter" :options="SIGNAL_OPTIONS" style="max-width: 180px" @change="resetToFirstPage" />
      <SearchableSelect v-if="show('ai_opinion')" v-model="aiOpinionFilter" :options="AI_OPINION_OPTIONS" style="max-width: 180px" @change="resetToFirstPage" />
      <span class="muted result-count">{{ filtered.length }} stocks</span>
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
.filter-bar {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 16px;
  flex-wrap: wrap;
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
