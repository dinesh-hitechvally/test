<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import client from '../api/client'
import { useStocksStore } from '../stores/stocks'
import { formatPrice } from '../utils/format'
import StatCard from '../components/StatCard.vue'
import SignalDistributionChart from '../components/SignalDistributionChart.vue'
import MarketBreadthChart from '../components/MarketBreadthChart.vue'
import SectorBreakdownChart from '../components/SectorBreakdownChart.vue'

const store = useStocksStore()
const router = useRouter()
const activeFilter = ref('')
const report = ref(null)
const loadingReport = ref(true)

const filters = [
  { value: '', label: 'All' },
  { value: 'strong_buy', label: 'Strong Buy' },
  { value: 'buy', label: 'Buy' },
  { value: 'hold', label: 'Hold' },
  { value: 'sell', label: 'Sell' },
  { value: 'strong_sell', label: 'Strong Sell' },
]

async function loadSignals() {
  await store.fetchTodaySignals(activeFilter.value || null)
}

async function loadReport() {
  loadingReport.value = true
  const { data } = await client.get('/reports/dashboard')
  report.value = data
  loadingReport.value = false
}

function setFilter(value) {
  activeFilter.value = value
  loadSignals()
}

function formatSignal(label) {
  return label.replace('_', ' ')
}

function changeTone(pct) {
  if (pct === null || pct === undefined) return ''
  return pct > 0 ? 'positive' : pct < 0 ? 'negative' : ''
}

onMounted(async () => {
  await Promise.all([loadSignals(), loadReport()])
})
</script>

<template>
  <div>
    <template v-if="report">
      <div class="grid grid-cards">
        <StatCard label="Stocks Tracked" :value="report.totals.stocks" :sub="`${report.totals.with_signals} have a signal`" />
        <StatCard
          label="Buy-leaning Signals"
          :value="report.signal_counts.strong_buy + report.signal_counts.buy"
          tone="positive"
          :sub="`${report.signal_counts.strong_buy} strong buy`"
        />
        <StatCard
          label="Sell-leaning Signals"
          :value="report.signal_counts.strong_sell + report.signal_counts.sell"
          tone="negative"
          :sub="`${report.signal_counts.strong_sell} strong sell`"
        />
        <StatCard
          label="Last Scrape"
          :value="report.last_scrape ? new Date(report.last_scrape.created_at).toLocaleTimeString() : 'Never'"
          :sub="report.last_scrape ? `${report.last_scrape.status} — ${report.last_scrape.records_processed} rows` : 'Use the topbar action'"
        />
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Signal Distribution</h3>
          <SignalDistributionChart :counts="report.signal_counts" />
        </div>
        <div class="card">
          <h3>Market Breadth</h3>
          <MarketBreadthChart :breadth="report.breadth" />
        </div>
        <div class="card">
          <h3>Sector Breakdown</h3>
          <SectorBreakdownChart :sectors="report.sector_breakdown" />
        </div>
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Top Gainers</h3>
          <table class="table">
            <tbody>
              <tr v-for="m in report.movers.gainers" :key="m.stock_id">
                <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: m.symbol } }">{{ m.symbol }}</RouterLink></td>
                <td class="muted">{{ m.company_name }}</td>
                <td>Rs. {{ formatPrice(m.close) }}</td>
                <td :class="changeTone(m.change_pct)">{{ m.change_pct > 0 ? '+' : '' }}{{ m.change_pct }}%</td>
              </tr>
            </tbody>
          </table>
          <p v-if="report.movers.gainers.length === 0" class="muted">Not enough data yet.</p>
        </div>
        <div class="card">
          <h3>Top Losers</h3>
          <table class="table">
            <tbody>
              <tr v-for="m in report.movers.losers" :key="m.stock_id">
                <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: m.symbol } }">{{ m.symbol }}</RouterLink></td>
                <td class="muted">{{ m.company_name }}</td>
                <td>Rs. {{ formatPrice(m.close) }}</td>
                <td :class="changeTone(m.change_pct)">{{ m.change_pct > 0 ? '+' : '' }}{{ m.change_pct }}%</td>
              </tr>
            </tbody>
          </table>
          <p v-if="report.movers.losers.length === 0" class="muted">Not enough data yet.</p>
        </div>
      </div>
    </template>

    <div class="page-header" style="margin-top: 24px">
      <h2 style="margin: 0">Today's Signal Feed</h2>
    </div>

    <div class="filters">
      <button
        v-for="f in filters"
        :key="f.value"
        class="btn-secondary btn"
        :class="{ active: activeFilter === f.value }"
        @click="setFilter(f.value)"
      >
        {{ f.label }}
      </button>
    </div>

    <p v-if="store.todaySignals.length === 0" class="muted" style="margin-top: 20px">
      No signals yet — use "Scrape Latest Data" in the top bar to pull today's prices, or import historical CSVs from
      the Stocks page so indicators have enough history to compute (at least 20 trading days).
    </p>

    <div class="card" style="margin-top: 20px" v-if="store.todaySignals.length">
      <table class="table signal-table">
        <thead>
          <tr>
            <th>Symbol</th>
            <th>Company</th>
            <th>Price</th>
            <th>Signal</th>
            <th>Reasons</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="stock in store.todaySignals"
            :key="stock.id"
            class="signal-row"
            @click="router.push({ name: 'stock-detail', params: { symbol: stock.symbol } })"
          >
            <td>
              <RouterLink :to="{ name: 'stock-detail', params: { symbol: stock.symbol } }" @click.stop>{{ stock.symbol }}</RouterLink>
            </td>
            <td class="muted">{{ stock.company_name }}</td>
            <td>Rs. {{ formatPrice(stock.latest_price?.close_price) }}</td>
            <td><span class="badge" :class="stock.latest_signal.signal">{{ formatSignal(stock.latest_signal.signal) }}</span></td>
            <td>
              <ul class="reasons">
                <li v-for="(reason, i) in stock.latest_signal.reasons" :key="i">{{ reason }}</li>
              </ul>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.filters {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 4px;
}

.filters .btn {
  padding: 6px 12px;
  font-size: 0.82rem;
}

.filters .active {
  background: #1e293b;
  color: #fff;
  border-color: #1e293b;
}

.signal-row {
  cursor: pointer;
}

.signal-row:hover {
  background: rgba(15, 23, 42, 0.03);
}

.signal-table td,
.signal-table th {
  vertical-align: top;
}

.reasons {
  margin: 0;
  padding-left: 18px;
  font-size: 0.8rem;
  color: var(--text-muted);
}
</style>
