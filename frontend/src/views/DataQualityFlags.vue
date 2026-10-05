<script setup>
import { computed, onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import { useSortableTable } from '../composables/useSortableTable'

const flags = ref([])
const loading = ref(true)
const resolvingId = ref(null)
const severityFilter = ref('')
const showResolved = ref(false)

const CHECK_LABELS = {
  invalid_ohlc: 'Invalid OHLC',
  abnormal_price_change: 'Abnormal Price Change',
  missing_volume: 'Missing Volume',
  corrected_historical_value: 'Corrected Historical Value',
  missing_trading_date: 'Missing Trading Date',
  unadjusted_corporate_action: 'Unadjusted Corporate Action',
}

const SEVERITY_BADGE = { critical: 'sell', warning: 'hold', info: 'buy' }

const flagsTable = useSortableTable(flags, { defaultKey: 'detected_at', defaultDir: 'desc' })
const { sorted: sortedFlags } = flagsTable

async function load() {
  loading.value = true
  try {
    flags.value = await marketApi.dataQualityFlags(severityFilter.value || null, showResolved.value)
  } finally {
    loading.value = false
  }
}

async function resolve(flag) {
  resolvingId.value = flag.id
  try {
    await marketApi.resolveDataQualityFlag(flag.id)
    flags.value = flags.value.filter((f) => f.id !== flag.id)
  } finally {
    resolvingId.value = null
  }
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Data Quality">
      Problems the data-quality checks found — invalid prices, abnormal moves, missing trading dates and
      likely-unadjusted corporate actions. Detected automatically as prices land and on a daily sweep; a
      <code>critical</code> row is worth checking before trusting that stock's indicators.
    </PageHeader>

    <div class="filters">
      <button
        v-for="opt in [{ value: '', label: 'All' }, { value: 'critical', label: 'Critical' }, { value: 'warning', label: 'Warning' }, { value: 'info', label: 'Info' }]"
        :key="opt.value"
        type="button"
        class="btn-secondary btn small"
        :class="{ active: severityFilter === opt.value }"
        @click="severityFilter = opt.value; load()"
      >
        {{ opt.label }}
      </button>
      <label class="resolved-toggle">
        <input type="checkbox" v-model="showResolved" @change="load" />
        <span class="muted small">Show resolved</span>
      </label>
    </div>

    <Card style="margin-top: 12px">
      <LoadingState v-if="loading" />
      <table v-align-numbers class="table" v-else-if="sortedFlags.length">
        <thead>
          <tr>
            <SortableTh :table="flagsTable" column="severity">Severity</SortableTh>
            <SortableTh :table="flagsTable" column="check_type">Check</SortableTh>
            <th>Stock</th>
            <SortableTh :table="flagsTable" column="trade_date">Date</SortableTh>
            <th>Message</th>
            <SortableTh :table="flagsTable" column="detected_at">Detected</SortableTh>
            <th v-if="!showResolved"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="f in sortedFlags" :key="f.id">
            <td><span class="badge" :class="SEVERITY_BADGE[f.severity]">{{ f.severity }}</span></td>
            <td>{{ CHECK_LABELS[f.check_type] || f.check_type }}</td>
            <td><StockLink v-if="f.stock" :symbol="f.stock.symbol" /><span v-else class="muted">Market-wide</span></td>
            <td class="muted">{{ f.trade_date || '—' }}</td>
            <td class="muted">{{ f.message }}</td>
            <td class="muted">{{ new Date(f.detected_at).toLocaleString() }}</td>
            <td v-if="!showResolved">
              <button class="btn-secondary btn small" :disabled="resolvingId === f.id" @click="resolve(f)">
                {{ resolvingId === f.id ? 'Resolving…' : 'Resolve' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-else>
        {{ showResolved ? 'No resolved flags.' : 'No open data-quality issues right now.' }}
      </EmptyState>
    </Card>
  </div>
</template>

<style scoped>
.filters {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 8px;
}

.small {
  font-size: 0.78rem;
  padding: 4px 10px;
}

.filters .active {
  background: #1e293b;
  color: #fff;
  border-color: #1e293b;
}

.resolved-toggle {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-left: 8px;
  cursor: pointer;
}

.resolved-toggle .small {
  padding: 0;
}
</style>
