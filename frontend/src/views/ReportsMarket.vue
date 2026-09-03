<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'
import StatCard from '../components/StatCard.vue'
import MarketBreadthChart from '../components/MarketBreadthChart.vue'
import SignalDistributionChart from '../components/SignalDistributionChart.vue'
import SectorPerformanceChart from '../components/SectorPerformanceChart.vue'
import TrendChart from '../components/TrendChart.vue'

const report = ref(null)
const loading = ref(true)

function changeTone(pct) {
  if (pct === null || pct === undefined) return ''
  return pct > 0 ? 'positive' : pct < 0 ? 'negative' : ''
}

const trendLabels = computed(() => report.value?.trend.map((t) => t.trade_date) ?? [])

const breadthSeries = computed(() => [
  { label: 'Advancing', data: report.value?.trend.map((t) => t.advancing) ?? [], color: '#15803d' },
  { label: 'Declining', data: report.value?.trend.map((t) => t.declining) ?? [], color: '#b91c1c' },
])

const turnoverSeries = computed(() => [
  { label: 'Total Turnover (Rs.)', data: report.value?.trend.map((t) => t.total_turnover) ?? [], color: '#2563eb' },
])

async function load() {
  loading.value = true
  const { data } = await client.get('/reports/market')
  report.value = data
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Market Report</h1>
    <p class="muted">A whole-market view — breadth, sector performance, and the biggest movers, as of the latest scrape.</p>

    <p v-if="loading" class="muted">Loading…</p>

    <template v-else-if="report">
      <div class="grid grid-cards">
        <StatCard label="Stocks Tracked" :value="report.totals.stocks" :sub="`${report.totals.with_signals} have a signal`" />
        <StatCard label="Advancing" :value="report.breadth.advancing" tone="positive" />
        <StatCard label="Declining" :value="report.breadth.declining" tone="negative" />
        <StatCard label="Unchanged" :value="report.breadth.unchanged" />
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Market Breadth</h3>
          <MarketBreadthChart :breadth="report.breadth" />
        </div>
        <div class="card">
          <h3>Signal Distribution</h3>
          <SignalDistributionChart :counts="report.signal_counts" />
        </div>
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>Advancing vs. Declining — Last 30 Days</h3>
        <TrendChart :labels="trendLabels" :series="breadthSeries" />
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>Market Turnover — Last 30 Days</h3>
        <TrendChart :labels="trendLabels" :series="turnoverSeries" />
      </div>

      <div class="grid" style="grid-template-columns: 1.3fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Sector Performance (Today)</h3>
          <SectorPerformanceChart :sectors="report.sector_performance" />
        </div>
        <div class="card">
          <h3>Sector Breakdown</h3>
          <table class="table">
            <thead>
              <tr><th>Sector</th><th>Stocks</th><th>Adv/Dec</th><th>Avg %</th></tr>
            </thead>
            <tbody>
              <tr v-for="s in report.sector_performance" :key="s.sector">
                <td>
                  <RouterLink :to="{ name: 'reports-sector', query: { name: s.sector } }">{{ s.sector }}</RouterLink>
                </td>
                <td>{{ s.stock_count }}</td>
                <td>{{ s.advancing }}/{{ s.declining }}</td>
                <td :class="changeTone(s.avg_change_pct)">{{ s.avg_change_pct !== null ? `${s.avg_change_pct > 0 ? '+' : ''}${s.avg_change_pct}%` : '—' }}</td>
              </tr>
            </tbody>
          </table>
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
