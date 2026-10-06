<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import * as reportsApi from '../api/reports'
import { useColumnOptions } from '../composables/useColumnOptions'
import { useSortableTable } from '../composables/useSortableTable'
import { changeTone } from '../utils/format'

// Screen Options: which columns to show. Saved in the browser, and the query asks the API only
// for the fields those columns need.
const columns = useColumnOptions('sector-list', [
  { key: 'sector', label: 'Sector', locked: true },
  { key: 'stock_count', label: 'Stocks' },
  { key: 'advancing', label: 'Advancing' },
  { key: 'declining', label: 'Declining' },
  { key: 'unchanged', label: 'Unchanged' },
  { key: 'advance_pct', label: 'Advancing %' },
  { key: 'avg_change_pct', label: 'Avg Change' },
  { key: 'total_turnover', label: 'Turnover' },
])
const show = (key) => columns.isVisible(key)

const sectors = ref([])
const loading = ref(true)
const error = ref('')

let latest = 0
async function load() {
  const request = ++latest
  loading.value = true
  error.value = ''
  try {
    const data = await reportsApi.sectorPerformance(columns.visibleKeys.value)
    if (request === latest) sectors.value = data // ignore an older response that arrives late
  } catch (e) {
    if (request === latest) error.value = e.response?.data?.message || 'Could not load sectors.'
  } finally {
    if (request === latest) loading.value = false
  }
}

let timer = null
watch(columns.visibleKeys, () => {
  clearTimeout(timer)
  timer = setTimeout(load, 250)
})
onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))

// ---- Filters (a filter only applies while its column is shown) ----
const search = ref('')
const direction = ref('') // '' | 'up' | 'down' | 'flat'
const minStocks = ref('')
const minTurnover = ref('')

const DIRECTIONS = [
  { value: '', label: 'All' },
  { value: 'up', label: '▲ Advancing' },
  { value: 'down', label: '▼ Declining' },
  { value: 'flat', label: 'Flat' },
]

const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v))
const unchanged = (s) => (s.stock_count ?? 0) - (s.advancing ?? 0) - (s.declining ?? 0)
const advancePct = (s) => (s.stock_count > 0 ? Math.round(((s.advancing ?? 0) / s.stock_count) * 1000) / 10 : null)

const filtered = computed(() => {
  let list = sectors.value

  const term = search.value.trim().toLowerCase()
  if (term) list = list.filter((s) => s.sector.toLowerCase().includes(term))

  if (show('avg_change_pct') && direction.value) {
    list = list.filter((s) => {
      if (s.avg_change_pct === null || s.avg_change_pct === undefined) return false
      if (direction.value === 'up') return s.avg_change_pct > 0
      if (direction.value === 'down') return s.avg_change_pct < 0
      return s.avg_change_pct === 0
    })
  }
  if (show('stock_count') && num(minStocks.value) !== null) list = list.filter((s) => s.stock_count >= num(minStocks.value))
  if (show('total_turnover') && num(minTurnover.value) !== null) list = list.filter((s) => (s.total_turnover ?? 0) >= num(minTurnover.value))

  return list
})

const activeFilters = computed(() => [
  search.value.trim(),
  show('avg_change_pct') && direction.value,
  show('stock_count') && minStocks.value !== '' && 'min stocks',
  show('total_turnover') && minTurnover.value !== '' && 'min turnover',
].filter(Boolean).length)

function clearFilters() {
  search.value = ''
  direction.value = ''
  minStocks.value = ''
  minTurnover.value = ''
}

// ---- Sorting ----
const table = useSortableTable(filtered, {
  defaultKey: 'avg_change_pct',
  defaultDir: 'desc',
  valueGetters: { unchanged, advance_pct: advancePct },
})
const { sorted } = table

// ---- Totals over the rows shown ----
const totals = computed(() => {
  const rows = filtered.value
  const sum = (key) => rows.reduce((total, s) => total + (s[key] ?? 0), 0)
  return {
    stock_count: sum('stock_count'),
    advancing: sum('advancing'),
    declining: sum('declining'),
    unchanged: rows.reduce((total, s) => total + unchanged(s), 0),
    total_turnover: sum('total_turnover'),
  }
})
const totalAdvancePct = computed(() => (totals.value.stock_count > 0 ? Math.round((totals.value.advancing / totals.value.stock_count) * 1000) / 10 : null))

const visibleCount = computed(() => columns.visibleKeys.value.length)

function formatChange(pct) {
  return pct === null || pct === undefined ? '—' : `${pct > 0 ? '+' : ''}${pct}%`
}
function formatInt(value) {
  return value === null || value === undefined ? '—' : Number(value).toLocaleString()
}
</script>

<template>
  <div>
    <ScreenOptions
      title="Columns"
      :options="columns.options"
      :visible="columns.visibleKeys.value"
      note="Only the data for the columns you leave on is requested from the server. Your choice is saved in this browser."
      @toggle="columns.toggle"
      @reset="columns.reset"
    />

    <p class="muted">
      How each sector is moving today: its stocks, how many advanced and declined, the average change and the turnover.
      Click a sector to open its page.
    </p>

    <p v-if="error" class="error-text">{{ error }}</p>
    <LoadingState v-if="loading && sectors.length === 0" />

    <template v-else>
      <div class="filters">
        <div class="filter-row">
          <input v-model="search" class="input search" placeholder="Search sector…" />

          <div v-if="show('avg_change_pct')" class="field">
            <label>Today's move</label>
            <div class="chips" role="group" aria-label="Direction of the sector's average change">
              <button v-for="d in DIRECTIONS" :key="d.value" type="button" class="chip" :class="{ on: direction === d.value }" @click="direction = d.value">
                {{ d.label }}
              </button>
            </div>
          </div>

          <div v-if="show('stock_count')" class="field">
            <label>Min stocks</label>
            <input v-model="minStocks" type="number" min="0" step="1" class="input" placeholder="e.g. 5" />
          </div>

          <div v-if="show('total_turnover')" class="field">
            <label>Min turnover (Rs.)</label>
            <input v-model="minTurnover" type="number" min="0" step="any" class="input" placeholder="e.g. 10000000" />
          </div>
        </div>

        <div class="filter-foot">
          <button v-if="activeFilters" type="button" class="clear" @click="clearFilters">Clear {{ activeFilters }} filter{{ activeFilters === 1 ? '' : 's' }}</button>
          <span class="muted result-count">{{ filtered.length }} of {{ sectors.length }} sectors</span>
        </div>
      </div>

      <Card :class="{ updating: loading }">
        <table v-align-numbers class="table">
          <thead>
            <tr>
              <SortableTh :table="table" column="sector">Sector</SortableTh>
              <SortableTh v-if="show('stock_count')" :table="table" column="stock_count">Stocks</SortableTh>
              <SortableTh v-if="show('advancing')" :table="table" column="advancing">Advancing</SortableTh>
              <SortableTh v-if="show('declining')" :table="table" column="declining">Declining</SortableTh>
              <SortableTh v-if="show('unchanged')" :table="table" column="unchanged">Unchanged</SortableTh>
              <SortableTh v-if="show('advance_pct')" :table="table" column="advance_pct">Advancing %</SortableTh>
              <SortableTh v-if="show('avg_change_pct')" :table="table" column="avg_change_pct">Avg Change</SortableTh>
              <SortableTh v-if="show('total_turnover')" :table="table" column="total_turnover">Turnover</SortableTh>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in sorted" :key="s.sector">
              <td>
                <RouterLink v-if="s.sector_id" :to="{ name: 'sector-detail', params: { id: s.sector_id } }">{{ s.sector }}</RouterLink>
                <span v-else>{{ s.sector }}</span>
              </td>
              <td v-if="show('stock_count')">{{ formatInt(s.stock_count) }}</td>
              <td v-if="show('advancing')" class="positive">{{ formatInt(s.advancing) }}</td>
              <td v-if="show('declining')" class="negative">{{ formatInt(s.declining) }}</td>
              <td v-if="show('unchanged')">{{ formatInt(unchanged(s)) }}</td>
              <td v-if="show('advance_pct')">{{ advancePct(s) !== null ? `${advancePct(s)}%` : '—' }}</td>
              <td v-if="show('avg_change_pct')" :class="changeTone(s.avg_change_pct)">{{ formatChange(s.avg_change_pct) }}</td>
              <td v-if="show('total_turnover')">{{ formatInt(s.total_turnover) }}</td>
            </tr>
            <tr v-if="sorted.length === 0">
              <td :colspan="visibleCount" class="muted empty">No sectors match these filters.</td>
            </tr>
          </tbody>
          <tfoot v-if="sorted.length > 0">
            <tr>
              <td>All shown ({{ sorted.length }})</td>
              <td v-if="show('stock_count')">{{ formatInt(totals.stock_count) }}</td>
              <td v-if="show('advancing')">{{ formatInt(totals.advancing) }}</td>
              <td v-if="show('declining')">{{ formatInt(totals.declining) }}</td>
              <td v-if="show('unchanged')">{{ formatInt(totals.unchanged) }}</td>
              <td v-if="show('advance_pct')">{{ totalAdvancePct !== null ? `${totalAdvancePct}%` : '—' }}</td>
              <td v-if="show('avg_change_pct')"></td>
              <td v-if="show('total_turnover')">{{ formatInt(totals.total_turnover) }}</td>
            </tr>
          </tfoot>
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
  margin: 16px 0;
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
  max-width: 260px;
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

.field > .input {
  width: 130px;
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

.empty {
  text-align: center;
  padding: 24px;
}

tfoot td {
  font-weight: 700;
  border-top: 2px solid var(--border);
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}

.updating {
  opacity: 0.55;
  transition: opacity 0.15s;
}
</style>
