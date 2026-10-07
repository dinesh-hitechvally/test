<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import * as reportsApi from '../api/reports'
import { useColumnOptions } from '../composables/useColumnOptions'
import { useSortableTable } from '../composables/useSortableTable'
import { changeTone, formatNumber } from '../utils/format'

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

// Columns worked out from the loaded fields.
const unchanged = (s) => (s.stock_count ?? 0) - (s.advancing ?? 0) - (s.declining ?? 0)
const advancePct = (s) => (s.stock_count > 0 ? Math.round(((s.advancing ?? 0) / s.stock_count) * 1000) / 10 : null)

// ---- Sorting ----
const table = useSortableTable(sectors, {
  defaultKey: 'avg_change_pct',
  defaultDir: 'desc',
  valueGetters: { unchanged, advance_pct: advancePct },
})
const { sorted } = table

// ---- Totals over the rows shown ----
const totals = computed(() => {
  const rows = sectors.value
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
  return formatNumber(value)
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

    <p v-if="error" class="error-text">{{ error }}</p>
    <LoadingState v-if="loading && sectors.length === 0" />

    <template v-else>
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
              <td :colspan="visibleCount" class="muted empty">No sectors yet.</td>
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
