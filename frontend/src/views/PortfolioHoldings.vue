<script setup>
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { usePortfolioStore } from '../stores/portfolio'
import { changeTone, formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const store = usePortfolioStore()

const holdingsTable = useSortableTable(
  computed(() => store.detail?.holdings ?? []),
  {
    valueGetters: {
      signal: (h) => h.latest_signal?.signal ?? null,
    },
  }
)
const { sorted } = holdingsTable

const decisions = computed(() => Object.fromEntries(store.sellChecks.map((d) => [d.stock_id, d])))

onMounted(async () => {
  await store.fetchPortfolios()
  if (store.activePortfolioId) await Promise.all([store.fetchDetail(store.activePortfolioId), store.fetchSellChecks(store.activePortfolioId)])
})
</script>

<template>
  <div>
    <p class="muted">
      Everything you currently hold, at a glance. To buy, sell, or set stop-loss/target levels, use
      <RouterLink :to="{ name: 'portfolio' }">Portfolio Overview</RouterLink>.
    </p>

    <LoadingState v-if="!store.detail" />

    <Card v-else>
      <table v-align-numbers class="table" v-if="store.detail.holdings.length">
        <thead>
          <tr>
            <SortableTh :table="holdingsTable" column="symbol">Symbol</SortableTh>
            <SortableTh :table="holdingsTable" column="sector">Sector</SortableTh>
            <SortableTh :table="holdingsTable" column="quantity">Qty</SortableTh>
            <SortableTh :table="holdingsTable" column="avg_cost">Avg Cost</SortableTh>
            <SortableTh :table="holdingsTable" column="invested">Invested</SortableTh>
            <SortableTh :table="holdingsTable" column="current_price">Current Price</SortableTh>
            <SortableTh :table="holdingsTable" column="current_value">Current Value</SortableTh>
            <SortableTh :table="holdingsTable" column="unrealized_pnl">Unrealized P&L</SortableTh>
            <SortableTh :table="holdingsTable" column="signal">Signal</SortableTh>
            <th>Sell check</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="h in sorted" :key="h.stock_id">
            <td><StockLink :symbol="h.symbol" /></td>
            <td class="muted">{{ h.sector || 'No Sector' }}</td>
            <td>
              {{ h.quantity }}
              <span v-if="h.bonus_shares_received > 0" class="muted small" :title="`Includes ${h.bonus_shares_received} bonus share(s) credited over time`">
                (+{{ h.bonus_shares_received }} bonus)
              </span>
            </td>
            <td>{{ formatPrice(h.avg_cost) }}</td>
            <td>{{ formatPrice(h.invested) }}</td>
            <td>{{ h.current_price !== null ? formatPrice(h.current_price) : '—' }}</td>
            <td>{{ h.current_value !== null ? formatPrice(h.current_value) : '—' }}</td>
            <td :class="changeTone(h.unrealized_pnl)">
              <span v-if="h.unrealized_pnl !== null">Rs. {{ formatPrice(h.unrealized_pnl) }} ({{ h.unrealized_pnl_pct }}%)</span>
              <span v-else>—</span>
            </td>
            <td>
              <SignalBadge v-if="h.latest_signal" :signal="h.latest_signal.signal" />
              <span v-else class="muted">No data</span>
            </td>
            <td>
              <SellBadge :decision="decisions[h.stock_id]" />
              <div v-if="decisions[h.stock_id]?.effective_stop" class="muted small">
                Stop {{ formatPrice(decisions[h.stock_id].effective_stop) }}<span v-if="decisions[h.stock_id].trailing_stop"> (trailing)</span>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-else>No open holdings — add a buy transaction from Portfolio Overview to get started.</EmptyState>
    </Card>
  </div>
</template>

<style scoped>
.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}

.small {
  font-size: 0.72rem;
}
</style>
