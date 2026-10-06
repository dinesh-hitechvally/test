<script setup>
import { computed, onMounted, ref } from 'vue'
import { usePortfolioStore } from '../stores/portfolio'
import { changeTone, formatPrice } from '../utils/format'
import { downloadFile } from '../api/client'
import { useSortableTable } from '../composables/useSortableTable'

const store = usePortfolioStore()

const exportError = ref('')

// A plain <a href> can't carry the Authorization header the API needs, so
// this fetches the file (with it) and saves it via downloadFile() instead.
async function exportCsv() {
  exportError.value = ''
  try {
    await downloadFile(`/portfolios/${store.activePortfolioId}/export`)
  } catch {
    exportError.value = 'Export failed.'
  }
}

const holdingsTable = useSortableTable(
  computed(() => store.detail?.holdings ?? []),
  { valueGetters: { signal: (h) => h.latest_signal?.signal ?? null } }
)
const { sorted: sortedHoldings } = holdingsTable

const realizedTable = useSortableTable(
  computed(() => store.detail?.realized ?? []),
  { defaultKey: 'transaction_date', defaultDir: 'desc' }
)
const { sorted: sortedRealized } = realizedTable

async function load() {
  if (store.portfolios.length === 0) await store.fetchPortfolios()
  if (store.activePortfolioId) {
    await store.fetchDetail(store.activePortfolioId)
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div v-if="store.activePortfolioId" class="page-header" style="justify-content: flex-end">
      <a v-if="store.activePortfolioId" href="#" class="btn" @click.prevent="exportCsv">Export CSV</a>
    </div>
    <p v-if="exportError" class="error-text">{{ exportError }}</p>

    <LoadingState v-if="!store.detail" />
    <template v-else>
      <div class="grid grid-cards">
        <StatCard label="Total Invested" :value="`Rs. ${formatPrice(store.detail.summary.total_invested)}`" />
        <StatCard label="Current Value" :value="`Rs. ${formatPrice(store.detail.summary.current_value)}`" />
        <StatCard
          label="Realized P&L"
          :value="`Rs. ${formatPrice(store.detail.summary.realized_pnl)}`"
          :tone="changeTone(store.detail.summary.realized_pnl)"
        />
        <StatCard
          label="Total P&L"
          :value="`Rs. ${formatPrice(store.detail.summary.total_pnl)}`"
          :tone="changeTone(store.detail.summary.total_pnl)"
        />
      </div>

      <Card title="Holdings Summary" style="margin-top: 16px">
        <table v-align-numbers class="table" v-if="store.detail.holdings.length">
          <thead>
            <tr>
              <SortableTh :table="holdingsTable" column="symbol">Symbol</SortableTh>
              <SortableTh :table="holdingsTable" column="quantity">Qty</SortableTh>
              <SortableTh :table="holdingsTable" column="avg_cost">Avg Cost</SortableTh>
              <SortableTh :table="holdingsTable" column="invested">Invested</SortableTh>
              <SortableTh :table="holdingsTable" column="current_value">Current Value</SortableTh>
              <SortableTh :table="holdingsTable" column="unrealized_pnl">Unrealized P&L</SortableTh>
              <SortableTh :table="holdingsTable" column="signal">Signal</SortableTh>
            </tr>
          </thead>
          <tbody>
            <tr v-for="h in sortedHoldings" :key="h.stock_id">
              <td>{{ h.symbol }}</td>
              <td>{{ h.quantity }}</td>
              <td>{{ formatPrice(h.avg_cost) }}</td>
              <td>{{ formatPrice(h.invested) }}</td>
              <td>{{ h.current_value !== null ? formatPrice(h.current_value) : '—' }}</td>
              <td :class="changeTone(h.unrealized_pnl)">{{ h.unrealized_pnl !== null ? formatPrice(h.unrealized_pnl) : '—' }}</td>
              <td>
                <SignalBadge v-if="h.latest_signal" :signal="h.latest_signal.signal" />
                <span v-else class="muted">No data</span>
              </td>
            </tr>
          </tbody>
        </table>
        <EmptyState v-else>No open holdings.</EmptyState>
      </Card>

      <Card title="Realized Gains/Losses" style="margin-top: 16px">
        <table v-align-numbers class="table" v-if="store.detail.realized.length">
          <thead>
            <tr>
              <SortableTh :table="realizedTable" column="transaction_date">Date</SortableTh>
              <SortableTh :table="realizedTable" column="symbol">Symbol</SortableTh>
              <SortableTh :table="realizedTable" column="quantity">Qty Sold</SortableTh>
              <SortableTh :table="realizedTable" column="sell_price">Sell Price</SortableTh>
              <SortableTh :table="realizedTable" column="avg_cost_at_time">Avg Cost</SortableTh>
              <SortableTh :table="realizedTable" column="realized_pnl">Realized P&L</SortableTh>
            </tr>
          </thead>
          <tbody>
            <tr v-for="line in sortedRealized" :key="line.transaction_id">
              <td>{{ line.transaction_date }}</td>
              <td>{{ line.symbol }}</td>
              <td>{{ line.quantity }}</td>
              <td>{{ formatPrice(line.sell_price) }}</td>
              <td>{{ formatPrice(line.avg_cost_at_time) }}</td>
              <td :class="changeTone(line.realized_pnl)">{{ formatPrice(line.realized_pnl) }}</td>
            </tr>
          </tbody>
        </table>
        <EmptyState v-else>No sales yet.</EmptyState>
      </Card>
    </template>
  </div>
</template>

<style scoped>
.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}
</style>
