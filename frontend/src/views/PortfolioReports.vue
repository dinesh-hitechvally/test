<script setup>
import { computed, onMounted } from 'vue'
import { usePortfolioStore } from '../stores/portfolio'
import { formatPrice } from '../utils/format'
import { apiBaseUrl } from '../api/client'
import StatCard from '../components/StatCard.vue'

const store = usePortfolioStore()

const exportUrl = computed(() => `${apiBaseUrl}/api/portfolios/${store.activePortfolioId}/export`)

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
            <tr><th>Symbol</th><th>Qty</th><th>Avg Cost</th><th>Invested</th><th>Current Value</th><th>Unrealized P&L</th><th>Signal</th></tr>
          </thead>
          <tbody>
            <tr v-for="h in store.detail.holdings" :key="h.stock_id">
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
            <tr><th>Date</th><th>Symbol</th><th>Qty Sold</th><th>Sell Price</th><th>Avg Cost</th><th>Realized P&L</th></tr>
          </thead>
          <tbody>
            <tr v-for="line in store.detail.realized" :key="line.transaction_id">
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
