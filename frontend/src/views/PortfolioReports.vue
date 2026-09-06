<script setup>
import { computed, onMounted } from 'vue'
import { usePortfolioStore } from '../stores/portfolio'
import { formatPrice } from '../utils/format'
import { apiBaseUrl } from '../api/client'
import StatCard from '../components/StatCard.vue'
import { useSortableTable } from '../composables/useSortableTable'

const store = usePortfolioStore()

const exportUrl = computed(() => `${apiBaseUrl}/api/portfolios/${store.activePortfolioId}/export`)

const {
  sorted: sortedHoldings,
  toggleSort: toggleHoldingsSort,
  sortIndicator: holdingsSortIndicator,
} = useSortableTable(
  computed(() => store.detail?.holdings ?? []),
  { valueGetters: { signal: (h) => h.latest_signal?.signal ?? null } }
)

const {
  sorted: sortedRealized,
  toggleSort: toggleRealizedSort,
  sortIndicator: realizedSortIndicator,
} = useSortableTable(
  computed(() => store.detail?.realized ?? []),
  { defaultKey: 'transaction_date', defaultDir: 'desc' }
)

function changeTone(value) {
  if (value === null || value === undefined) return ''
  return value > 0 ? 'positive' : value < 0 ? 'negative' : ''
}

function formatSignal(label) {
  return label.replace('_', ' ')
}

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
    <div class="page-header">
      <h1>Portfolio Reports</h1>
      <a v-if="store.activePortfolioId" class="btn" :href="exportUrl">Export CSV</a>
    </div>

    <p v-if="!store.detail" class="muted">Loading…</p>
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

      <div class="card" style="margin-top: 16px">
        <h3>Holdings Summary</h3>
        <table class="table" v-if="store.detail.holdings.length">
          <thead>
            <tr>
              <th class="sortable" @click="toggleHoldingsSort('symbol')">Symbol {{ holdingsSortIndicator('symbol') }}</th>
              <th class="sortable" @click="toggleHoldingsSort('quantity')">Qty {{ holdingsSortIndicator('quantity') }}</th>
              <th class="sortable" @click="toggleHoldingsSort('avg_cost')">Avg Cost {{ holdingsSortIndicator('avg_cost') }}</th>
              <th class="sortable" @click="toggleHoldingsSort('invested')">Invested {{ holdingsSortIndicator('invested') }}</th>
              <th class="sortable" @click="toggleHoldingsSort('current_value')">Current Value {{ holdingsSortIndicator('current_value') }}</th>
              <th class="sortable" @click="toggleHoldingsSort('unrealized_pnl')">Unrealized P&L {{ holdingsSortIndicator('unrealized_pnl') }}</th>
              <th class="sortable" @click="toggleHoldingsSort('signal')">Signal {{ holdingsSortIndicator('signal') }}</th>
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
                <span v-if="h.latest_signal" class="badge" :class="h.latest_signal.signal">{{ formatSignal(h.latest_signal.signal) }}</span>
                <span v-else class="muted">No data</span>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">No open holdings.</p>
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>Realized Gains/Losses</h3>
        <table class="table" v-if="store.detail.realized.length">
          <thead>
            <tr>
              <th class="sortable" @click="toggleRealizedSort('transaction_date')">Date {{ realizedSortIndicator('transaction_date') }}</th>
              <th class="sortable" @click="toggleRealizedSort('symbol')">Symbol {{ realizedSortIndicator('symbol') }}</th>
              <th class="sortable" @click="toggleRealizedSort('quantity')">Qty Sold {{ realizedSortIndicator('quantity') }}</th>
              <th class="sortable" @click="toggleRealizedSort('sell_price')">Sell Price {{ realizedSortIndicator('sell_price') }}</th>
              <th class="sortable" @click="toggleRealizedSort('avg_cost_at_time')">Avg Cost {{ realizedSortIndicator('avg_cost_at_time') }}</th>
              <th class="sortable" @click="toggleRealizedSort('realized_pnl')">Realized P&L {{ realizedSortIndicator('realized_pnl') }}</th>
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
        <p v-else class="muted">No sales yet.</p>
      </div>
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
