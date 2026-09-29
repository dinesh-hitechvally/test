<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import * as reportsApi from '../api/reports'
import { useStocksStore } from '../stores/stocks'
import { useAuthStore } from '../stores/auth'
import { changeTone, formatPrice } from '../utils/format'
import SignalDistributionChart from '../components/charts/SignalDistributionChart.vue'
import MarketBreadthChart from '../components/charts/MarketBreadthChart.vue'
import SectorBreakdownChart from '../components/charts/SectorBreakdownChart.vue'
import NavIcon from '../components/layout/NavIcon.vue'
import { useSortableTable } from '../composables/useSortableTable'

const store = useStocksStore()
const auth = useAuthStore()

const greeting = computed(() => {
  const hour = new Date().getHours()
  const name = auth.user?.name?.split(' ')[0]
  const time = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'
  return name ? `${time}, ${name}` : time
})

const todayLabel = new Date().toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })

const signals = useSortableTable(
  computed(() => store.todaySignals),
  {
    valueGetters: {
      symbol: (s) => s.symbol,
      company_name: (s) => s.company_name,
      price: (s) => s.latest_price?.close_price !== undefined ? Number(s.latest_price.close_price) : null,
      signal: (s) => s.latest_signal?.signal ?? null,
    },
  }
)
const { sorted: sortedSignals } = signals
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
  report.value = await reportsApi.dashboard()
  loadingReport.value = false
}

function setFilter(value) {
  activeFilter.value = value
  loadSignals()
}

onMounted(async () => {
  await Promise.all([loadSignals(), loadReport()])
})
</script>

<template>
  <div>
    <div class="dash-greeting">
      <h1>{{ greeting }}</h1>
      <p class="muted">{{ todayLabel }}</p>
    </div>

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
        <Card>
          <h3 class="card-heading"><span class="card-icon"><NavIcon name="target" /></span>Signal Distribution</h3>
          <SignalDistributionChart :counts="report.signal_counts" />
        </Card>
        <Card>
          <h3 class="card-heading"><span class="card-icon"><NavIcon name="pulse" /></span>Market Breadth</h3>
          <MarketBreadthChart :breadth="report.breadth" />
        </Card>
        <Card>
          <h3 class="card-heading"><span class="card-icon"><NavIcon name="chart" /></span>Sector Breakdown</h3>
          <SectorBreakdownChart :sectors="report.sector_breakdown" />
        </Card>
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <Card>
          <h3 class="card-heading positive-heading"><span class="card-icon positive-icon"><NavIcon name="target" /></span>Top Gainers</h3>
          <table v-align-numbers class="table">
            <tbody>
              <tr v-for="m in report.movers.gainers" :key="m.stock_id">
                <td><StockLink :symbol="m.symbol" /></td>
                <td class="muted">{{ m.company_name }}</td>
                <td>Rs. {{ formatPrice(m.close) }}</td>
                <td :class="changeTone(m.change_pct)">{{ m.change_pct > 0 ? '+' : '' }}{{ m.change_pct }}%</td>
              </tr>
            </tbody>
          </table>
          <EmptyState v-if="report.movers.gainers.length === 0">Not enough data yet.</EmptyState>
        </Card>
        <Card>
          <h3 class="card-heading negative-heading"><span class="card-icon negative-icon"><NavIcon name="target" /></span>Top Losers</h3>
          <table v-align-numbers class="table">
            <tbody>
              <tr v-for="m in report.movers.losers" :key="m.stock_id">
                <td><StockLink :symbol="m.symbol" /></td>
                <td class="muted">{{ m.company_name }}</td>
                <td>Rs. {{ formatPrice(m.close) }}</td>
                <td :class="changeTone(m.change_pct)">{{ m.change_pct > 0 ? '+' : '' }}{{ m.change_pct }}%</td>
              </tr>
            </tbody>
          </table>
          <EmptyState v-if="report.movers.losers.length === 0">Not enough data yet.</EmptyState>
        </Card>
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

    <EmptyState v-if="store.todaySignals.length === 0" style="margin-top: 20px">
      No signals yet — the scheduled market sync pulls today's prices automatically, or import historical CSVs from
      the Stocks page so indicators have enough history to compute (at least 20 trading days).
    </EmptyState>

    <Card style="margin-top: 20px" v-if="store.todaySignals.length">
      <table v-align-numbers class="table signal-table">
        <thead>
          <tr>
            <SortableTh :table="signals" column="symbol">Symbol</SortableTh>
            <SortableTh :table="signals" column="company_name">Company</SortableTh>
            <SortableTh :table="signals" column="price">Price</SortableTh>
            <SortableTh :table="signals" column="signal">Signal</SortableTh>
            <th>Reasons</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="stock in sortedSignals"
            :key="stock.id"
            class="signal-row"
            @click="router.push({ name: 'stock-detail', params: { symbol: stock.symbol } })"
          >
            <td>
              <RouterLink :to="{ name: 'stock-detail', params: { symbol: stock.symbol } }" @click.stop>{{ stock.symbol }}</RouterLink>
            </td>
            <td class="muted">{{ stock.company_name }}</td>
            <td>Rs. {{ formatPrice(stock.latest_price?.close_price) }}</td>
            <td><SignalBadge :signal="stock.latest_signal.signal" /></td>
            <td>
              <ul class="reasons">
                <li v-for="(reason, i) in stock.latest_signal.reasons" :key="i">{{ reason }}</li>
              </ul>
            </td>
          </tr>
        </tbody>
      </table>
    </Card>
  </div>
</template>

<style scoped>
.dash-greeting {
  margin-bottom: 20px;
}

.dash-greeting h1 {
  margin-bottom: 2px;
}

.card-heading {
  display: flex;
  align-items: center;
  gap: 8px;
}

.card-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 8px;
  background: var(--primary-soft);
  color: var(--primary);
  flex-shrink: 0;
}

.card-icon svg {
  width: 14px;
  height: 14px;
}

.positive-heading .card-icon.positive-icon {
  background: var(--strong-buy-bg);
  color: var(--strong-buy);
}

.negative-heading .card-icon.negative-icon {
  background: var(--strong-sell-bg);
  color: var(--strong-sell);
}

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
