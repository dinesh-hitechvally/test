<script setup>
import { computed, onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import { formatNumber, formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

// Every stock's fundamentals (EPS, P/E, book value, P/BV, return on equity, market cap). The filters and the quick
// views work on the loaded list, so they are instant. Fundamentals are a snapshot refreshed weekly (fetch/fundamentals).
const rows = ref([])
const loading = ref(true)
const error = ref('')

const preset = ref('')
const search = ref('')
const sector = ref('')
const peMax = ref('')
const pbvMax = ref('')
const roeMin = ref('')
const capMin = ref('') // in Rs. crore

const PRESETS = [
  { value: '', label: 'All', hint: 'Every stock with fundamentals' },
  { value: 'profitable', label: 'Profitable', hint: 'EPS above zero' },
  { value: 'loss', label: 'Loss-making', hint: 'EPS zero or below' },
  { value: 'cheap', label: 'Cheap earnings', hint: 'P/E under 10' },
  { value: 'below_book', label: 'Below book value', hint: 'P/BV under 1' },
  { value: 'high_roe', label: 'High return', hint: 'Return on equity 12% or more' },
]

const MATCHES = {
  '': () => true,
  profitable: (r) => r.eps !== null && r.eps > 0,
  loss: (r) => r.eps !== null && r.eps <= 0,
  cheap: (r) => r.pe_ratio !== null && r.pe_ratio > 0 && r.pe_ratio < 10,
  below_book: (r) => r.pbv !== null && r.pbv > 0 && r.pbv < 1,
  high_roe: (r) => r.roe_pct !== null && r.roe_pct >= 12,
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    rows.value = await marketApi.fundamentalsBoard()
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load the fundamentals.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

const sectorOf = (r) => r.sector || 'No Sector'
const sectorOptions = computed(() => [
  { value: '', label: 'All sectors' },
  ...Array.from(new Set(rows.value.map(sectorOf))).sort().map((s) => ({ value: s, label: s })),
])

const counts = computed(() => Object.fromEntries(PRESETS.map((p) => [p.value, rows.value.filter(MATCHES[p.value]).length])))

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase()
  const num = (v) => (v === '' || v === null ? null : Number(v))
  const pe = num(peMax.value)
  const pbv = num(pbvMax.value)
  const roe = num(roeMin.value)
  const cap = num(capMin.value)

  return rows.value.filter((r) => {
    if (!MATCHES[preset.value](r)) return false
    if (term && !(r.symbol.toLowerCase().includes(term) || (r.company_name || '').toLowerCase().includes(term))) return false
    if (sector.value && sectorOf(r) !== sector.value) return false
    if (pe !== null && !(r.pe_ratio !== null && r.pe_ratio > 0 && r.pe_ratio <= pe)) return false
    if (pbv !== null && !(r.pbv !== null && r.pbv <= pbv)) return false
    if (roe !== null && !(r.roe_pct !== null && r.roe_pct >= roe)) return false
    if (cap !== null && !(r.market_cap !== null && r.market_cap >= cap * 1e7)) return false
    return true
  })
})

const activeFilters = computed(() => [search.value.trim(), sector.value, peMax.value, pbvMax.value, roeMin.value, capMin.value].filter((v) => v !== '').length)

function clearFilters() {
  search.value = ''
  sector.value = ''
  peMax.value = ''
  pbvMax.value = ''
  roeMin.value = ''
  capMin.value = ''
}

const table = useSortableTable(filtered, {
  defaultKey: 'symbol',
  defaultDir: 'asc',
  valueGetters: {
    sector: (r) => sectorOf(r),
    close: (r) => (r.close !== null ? Number(r.close) : null),
  },
})
const { sorted } = table

const average = (list) => (list.length ? list.reduce((a, b) => a + b, 0) / list.length : null)
const summary = computed(() => ({
  pe: average(filtered.value.filter((r) => r.pe_ratio > 0).map((r) => r.pe_ratio)),
  roe: average(filtered.value.filter((r) => r.roe_pct !== null).map((r) => r.roe_pct)),
}))

const num = (v, d = 2) => (v === null || v === undefined ? '—' : Number(v).toFixed(d))
const pct = (v) => (v === null || v === undefined ? '—' : `${Number(v).toFixed(2)}%`)
const crore = (v) => (v === null || v === undefined ? '—' : `Rs. ${formatNumber(v / 1e7, { decimals: 2 })} Cr`)
const tone = (v) => (v === null || v === undefined ? '' : v > 0 ? 'positive' : v < 0 ? 'negative' : '')
</script>

<template>
  <div>
    <LoadingState v-if="loading" />

    <Card v-else-if="error">
      <EmptyState>{{ error }}</EmptyState>
      <button class="btn" @click="load">Try again</button>
    </Card>

    <Card v-else-if="!rows.length">
      <EmptyState>No fundamentals yet. They are fetched, one stock at a time, by the fetch/fundamentals cron.</EmptyState>
    </Card>

    <template v-else>
      <div class="filters">
        <div class="filter-row">
          <div class="field">
            <label>Quick view</label>
            <div class="chips" role="group" aria-label="Quick view">
              <button v-for="p in PRESETS" :key="p.value" type="button" class="chip" :class="{ on: preset === p.value }" :title="p.hint" @click="preset = p.value">
                {{ p.label }} <span class="count">{{ counts[p.value] }}</span>
              </button>
            </div>
          </div>
        </div>

        <div class="filter-row">
          <div class="field">
            <label>Search</label>
            <input v-model="search" class="input search" placeholder="Symbol or company…" />
          </div>
          <div class="field">
            <label>Sector</label>
            <SearchableSelect v-model="sector" :options="sectorOptions" style="min-width: 190px" />
          </div>
          <div class="field">
            <label>P/E up to</label>
            <input v-model="peMax" type="number" min="0" step="any" class="input num" placeholder="e.g. 15" />
          </div>
          <div class="field">
            <label>P/BV up to</label>
            <input v-model="pbvMax" type="number" min="0" step="any" class="input num" placeholder="e.g. 2" />
          </div>
          <div class="field">
            <label>ROE at least %</label>
            <input v-model="roeMin" type="number" step="any" class="input num" placeholder="e.g. 10" />
          </div>
          <div class="field">
            <label>Market cap ≥ (Rs. Cr)</label>
            <input v-model="capMin" type="number" min="0" step="any" class="input num" placeholder="e.g. 1000" />
          </div>
        </div>

        <div class="filter-foot">
          <button v-if="activeFilters" type="button" class="clear" @click="clearFilters">Clear {{ activeFilters }} filter{{ activeFilters === 1 ? '' : 's' }}</button>
          <span class="muted small">ROE = EPS ÷ book value. P/E is only meaningful for profitable companies. Debentures, preference shares and mutual funds have no fundamentals.</span>
          <span class="muted result-count">
            {{ filtered.length }} of {{ rows.length }} stocks
            <template v-if="summary.pe !== null"> · average P/E {{ num(summary.pe, 1) }}</template>
            <template v-if="summary.roe !== null"> · average ROE {{ num(summary.roe, 1) }}%</template>
          </span>
        </div>
      </div>

      <Card>
        <table v-align-numbers class="table">
          <thead>
            <tr>
              <SortableTh :table="table" column="symbol">Symbol</SortableTh>
              <SortableTh :table="table" column="company_name">Company</SortableTh>
              <SortableTh :table="table" column="sector">Sector</SortableTh>
              <SortableTh :table="table" column="close">Price</SortableTh>
              <SortableTh :table="table" column="eps">EPS</SortableTh>
              <SortableTh :table="table" column="pe_ratio">P/E</SortableTh>
              <SortableTh :table="table" column="book_value">Book value</SortableTh>
              <SortableTh :table="table" column="pbv">P/BV</SortableTh>
              <SortableTh :table="table" column="roe_pct">ROE</SortableTh>
              <SortableTh :table="table" column="market_cap">Market cap</SortableTh>
              <SortableTh :table="table" column="one_year_yield_pct">1-year yield</SortableTh>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in sorted" :key="r.stock_id">
              <td><StockLink :symbol="r.symbol" /></td>
              <td class="muted">{{ r.company_name }}</td>
              <td class="muted">{{ sectorOf(r) }}</td>
              <td class="nowrap">{{ r.close !== null ? `Rs. ${formatPrice(r.close)}` : '—' }}</td>
              <td :class="tone(r.eps)" :title="r.eps_fiscal_year ? `Fiscal year ${r.eps_fiscal_year}` : ''">{{ num(r.eps) }}</td>
              <td>{{ r.pe_ratio !== null && r.pe_ratio > 0 ? num(r.pe_ratio) : '—' }}</td>
              <td>{{ num(r.book_value) }}</td>
              <td>{{ num(r.pbv) }}</td>
              <td :class="tone(r.roe_pct)">{{ pct(r.roe_pct) }}</td>
              <td class="nowrap">{{ crore(r.market_cap) }}</td>
              <td :class="tone(r.one_year_yield_pct)">{{ pct(r.one_year_yield_pct) }}</td>
            </tr>
            <tr v-if="!sorted.length">
              <td colspan="11" class="muted" style="text-align: center; padding: 24px">No stocks match these filters.</td>
            </tr>
          </tbody>
        </table>
      </Card>
    </template>
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

.search {
  width: 220px;
}

.num {
  width: 130px;
}

.chips {
  display: inline-flex;
  flex-wrap: wrap;
  border: 1px solid var(--border);
  border-radius: 8px;
  overflow: hidden;
}

.chip {
  border: 0;
  border-right: 1px solid var(--border);
  background: transparent;
  color: var(--text-muted);
  padding: 6px 12px;
  font: inherit;
  font-size: 0.85rem;
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

.count {
  margin-left: 4px;
  font-size: 0.75rem;
  opacity: 0.8;
}

.filter-foot {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
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

.nowrap {
  white-space: nowrap;
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}
</style>
