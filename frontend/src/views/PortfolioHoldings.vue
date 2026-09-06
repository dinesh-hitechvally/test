<script setup>
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { usePortfolioStore } from '../stores/portfolio'
import { formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const store = usePortfolioStore()

const { sorted, toggleSort, sortIndicator } = useSortableTable(
  computed(() => store.detail?.holdings ?? []),
  {
    valueGetters: {
      signal: (h) => h.latest_signal?.signal ?? null,
    },
  }
)

function changeTone(value) {
  if (value === null || value === undefined) return ''
  return value > 0 ? 'positive' : value < 0 ? 'negative' : ''
}

function formatSignal(label) {
  return label.replace('_', ' ')
}

onMounted(async () => {
  await store.fetchPortfolios()
  if (store.activePortfolioId) await store.fetchDetail(store.activePortfolioId)
})
</script>

<template>
  <div>
    <h1>Holdings</h1>
    <p class="muted">
      Everything you currently hold, at a glance. To buy, sell, or set stop-loss/target levels, use
      <RouterLink :to="{ name: 'portfolio' }">Portfolio Overview</RouterLink>.
    </p>

    <p v-if="!store.detail" class="muted">Loading…</p>

    <div v-else class="card">
      <table class="table" v-if="store.detail.holdings.length">
        <thead>
          <tr>
            <th class="sortable" @click="toggleSort('symbol')">Symbol {{ sortIndicator('symbol') }}</th>
            <th class="sortable" @click="toggleSort('sector')">Sector {{ sortIndicator('sector') }}</th>
            <th class="sortable" @click="toggleSort('quantity')">Qty {{ sortIndicator('quantity') }}</th>
            <th class="sortable" @click="toggleSort('avg_cost')">Avg Cost {{ sortIndicator('avg_cost') }}</th>
            <th class="sortable" @click="toggleSort('invested')">Invested {{ sortIndicator('invested') }}</th>
            <th class="sortable" @click="toggleSort('current_price')">Current Price {{ sortIndicator('current_price') }}</th>
            <th class="sortable" @click="toggleSort('current_value')">Current Value {{ sortIndicator('current_value') }}</th>
            <th class="sortable" @click="toggleSort('unrealized_pnl')">Unrealized P&L {{ sortIndicator('unrealized_pnl') }}</th>
            <th class="sortable" @click="toggleSort('signal')">Signal {{ sortIndicator('signal') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="h in sorted" :key="h.stock_id">
            <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: h.symbol } }">{{ h.symbol }}</RouterLink></td>
            <td class="muted">{{ h.sector || 'Other' }}</td>
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
              <span v-if="h.latest_signal" class="badge" :class="h.latest_signal.signal">{{ formatSignal(h.latest_signal.signal) }}</span>
              <span v-else class="muted">No data</span>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">No open holdings — add a buy transaction from Portfolio Overview to get started.</p>
    </div>
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
