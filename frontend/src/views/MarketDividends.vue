<script setup>
import { computed, onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import { useColumnOptions } from '../composables/useColumnOptions'
import { useSortableTable } from '../composables/useSortableTable'

// Every stock's dividend / bonus declarations on record. The filters work on the loaded list, so they are instant.
// Fiscal years are Nepali (B.S.) years such as 2081/2082, shown as the source gives them.
// Screen Options: which columns this page shows, saved in this browser. A filter whose column is switched off is hidden
// and ignored, so what you see always matches what is being filtered.
const columns = useColumnOptions('dividends', [
  { key: 'symbol', label: 'Symbol', locked: true },
  { key: 'company_name', label: 'Company' },
  { key: 'sector', label: 'Sector' },
  { key: 'fiscal_year', label: 'Fiscal year', locked: true },
  { key: 'cash', label: 'Cash %' },
  { key: 'bonus', label: 'Bonus %' },
  { key: 'total', label: 'Total %' },
  { key: 'announced', label: 'Announced' },
  { key: 'book_closure', label: 'Book closure' },
  { key: 'distribution', label: 'Distribution' },
])
const show = (key) => columns.isVisible(key)

const rows = ref([])
const loading = ref(true)
const error = ref('')

const fiscalYear = ref('')
const search = ref('')
const sector = ref('')
const kind = ref('') // '' | cash | bonus
const minTotal = ref('')

const KINDS = [
  { value: '', label: 'All' },
  { value: 'cash', label: 'Cash dividend' },
  { value: 'bonus', label: 'Bonus shares' },
]

async function load() {
  loading.value = true
  error.value = ''
  try {
    rows.value = await marketApi.marketDividends()
    // Open on the newest fiscal year that has declarations.
    fiscalYear.value = rows.value.length ? [...new Set(rows.value.map((r) => r.fiscal_year))].sort().reverse()[0] : ''
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load the dividends.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

const sectorOf = (r) => r.sector || 'No Sector'

const yearOptions = computed(() => [
  { value: '', label: 'All fiscal years' },
  ...[...new Set(rows.value.map((r) => r.fiscal_year))].sort().reverse().map((y) => ({ value: y, label: y })),
])
const sectorOptions = computed(() => [
  { value: '', label: 'All sectors' },
  ...Array.from(new Set(rows.value.map(sectorOf))).sort().map((s) => ({ value: s, label: s })),
])

// A filter belongs to a column: switch the column off and the filter goes with it.
const sectorFilterOn = computed(() => show('sector'))
const kindFilterOn = computed(() => show('cash') || show('bonus'))
const totalFilterOn = computed(() => show('total'))

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase()
  const min = minTotal.value === '' ? null : Number(minTotal.value)

  return rows.value.filter((r) => {
    if (fiscalYear.value && r.fiscal_year !== fiscalYear.value) return false
    if (term && !(r.symbol.toLowerCase().includes(term) || (r.company_name || '').toLowerCase().includes(term))) return false
    if (sectorFilterOn.value && sector.value && sectorOf(r) !== sector.value) return false
    if (kindFilterOn.value && kind.value === 'cash' && !(r.cash_dividend_pct > 0)) return false
    if (kindFilterOn.value && kind.value === 'bonus' && !(r.bonus_share_pct > 0)) return false
    if (totalFilterOn.value && min !== null && !((r.total_dividend_pct ?? -1) >= min)) return false
    return true
  })
})

const activeFilters = computed(
  () => [search.value.trim(), sectorFilterOn.value && sector.value, kindFilterOn.value && kind.value, totalFilterOn.value && minTotal.value].filter((v) => v !== '' && v !== false).length
)

// Visible columns, for the "no match" row to span.
const colCount = computed(() => 2 + ['company_name', 'sector', 'cash', 'bonus', 'total', 'announced', 'book_closure', 'distribution'].filter(show).length)

function clearFilters() {
  search.value = ''
  sector.value = ''
  kind.value = ''
  minTotal.value = ''
}

const table = useSortableTable(filtered, {
  defaultKey: 'total_dividend_pct',
  defaultDir: 'desc',
  valueGetters: { sector: (r) => sectorOf(r) },
})
const { sorted } = table

const pct = (v) => (v === null || v === undefined ? '—' : `${Number(v).toFixed(2)}%`)
const date = (v) => v || '—'

// Stocks shown and the combined payout of what is on screen — a quick read of the filtered list.
const summary = computed(() => ({
  stocks: new Set(filtered.value.map((r) => r.stock_id)).size,
  avgTotal: filtered.value.length ? filtered.value.reduce((sum, r) => sum + (r.total_dividend_pct ?? 0), 0) / filtered.value.length : null,
}))
</script>

<template>
  <div>
    <ScreenOptions
      title="Columns"
      :options="columns.options"
      :visible="columns.visibleKeys.value"
      note="Your choice is saved in this browser. A filter disappears with its column."
      @toggle="columns.toggle"
      @reset="columns.reset"
    />

    <LoadingState v-if="loading" />

    <Card v-else-if="error">
      <EmptyState>{{ error }}</EmptyState>
      <button class="btn" @click="load">Try again</button>
    </Card>

    <Card v-else-if="!rows.length">
      <EmptyState>No dividend data yet. It is fetched by the fetch/dividends cron.</EmptyState>
    </Card>

    <template v-else>
      <div class="filters">
        <div class="filter-row">
          <div class="field">
            <label>Fiscal year</label>
            <SearchableSelect v-model="fiscalYear" :options="yearOptions" style="min-width: 170px" />
          </div>
          <div class="field">
            <label>Search</label>
            <input v-model="search" class="input search" placeholder="Symbol or company…" />
          </div>
          <div v-if="sectorFilterOn" class="field">
            <label>Sector</label>
            <SearchableSelect v-model="sector" :options="sectorOptions" style="min-width: 190px" />
          </div>
          <div v-if="kindFilterOn" class="field">
            <label>Pays</label>
            <div class="chips" role="group" aria-label="Kind of payout">
              <button v-for="k in KINDS" :key="k.value" type="button" class="chip" :class="{ on: kind === k.value }" @click="kind = k.value">{{ k.label }}</button>
            </div>
          </div>
          <div v-if="totalFilterOn" class="field">
            <label>Min total %</label>
            <input v-model="minTotal" type="number" min="0" step="any" class="input" placeholder="e.g. 10" style="width: 110px" />
          </div>
        </div>

        <div class="filter-foot">
          <button v-if="activeFilters" type="button" class="clear" @click="clearFilters">Clear {{ activeFilters }} filter{{ activeFilters === 1 ? '' : 's' }}</button>
          <span class="muted small">Percentages are of face value. For yield rankings against today's price, see Reports &gt; Dividend Report.</span>
          <span class="muted result-count">
            {{ filtered.length }} declaration{{ filtered.length === 1 ? '' : 's' }} · {{ summary.stocks }} stock{{ summary.stocks === 1 ? '' : 's' }}
            <template v-if="summary.avgTotal !== null"> · average total {{ pct(summary.avgTotal) }}</template>
          </span>
        </div>
      </div>

      <Card>
        <table v-align-numbers class="table">
          <thead>
            <tr>
              <SortableTh :table="table" column="symbol">Symbol</SortableTh>
              <SortableTh v-if="show('company_name')" :table="table" column="company_name">Company</SortableTh>
              <SortableTh v-if="show('sector')" :table="table" column="sector">Sector</SortableTh>
              <SortableTh :table="table" column="fiscal_year">Fiscal year</SortableTh>
              <SortableTh v-if="show('cash')" :table="table" column="cash_dividend_pct">Cash %</SortableTh>
              <SortableTh v-if="show('bonus')" :table="table" column="bonus_share_pct">Bonus %</SortableTh>
              <SortableTh v-if="show('total')" :table="table" column="total_dividend_pct">Total %</SortableTh>
              <SortableTh v-if="show('announced')" :table="table" column="announcement_date">Announced</SortableTh>
              <SortableTh v-if="show('book_closure')" :table="table" column="book_closure_date">Book closure</SortableTh>
              <SortableTh v-if="show('distribution')" :table="table" column="distribution_date">Distribution</SortableTh>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in sorted" :key="r.stock_id + r.fiscal_year">
              <td><StockLink :symbol="r.symbol" /></td>
              <td v-if="show('company_name')" class="muted">{{ r.company_name }}</td>
              <td v-if="show('sector')" class="muted">{{ sectorOf(r) }}</td>
              <td>{{ r.fiscal_year }}</td>
              <td v-if="show('cash')">{{ pct(r.cash_dividend_pct) }}</td>
              <td v-if="show('bonus')">{{ pct(r.bonus_share_pct) }}</td>
              <td v-if="show('total')" class="positive"><strong>{{ pct(r.total_dividend_pct) }}</strong></td>
              <td v-if="show('announced')" class="muted">{{ date(r.announcement_date) }}</td>
              <td v-if="show('book_closure')" class="muted">{{ date(r.book_closure_date) }}</td>
              <td v-if="show('distribution')" class="muted">{{ date(r.distribution_date) }}</td>
            </tr>
            <tr v-if="!sorted.length">
              <td :colspan="colCount" class="muted" style="text-align: center; padding: 24px">No dividends match these filters.</td>
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

.positive {
  color: var(--strong-buy);
}
</style>
