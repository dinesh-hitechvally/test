<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import * as reportsApi from '../api/reports'
import { changeTone, formatPrice } from '../utils/format'
import MarketBreadthChart from '../components/charts/MarketBreadthChart.vue'
import SignalDistributionChart from '../components/charts/SignalDistributionChart.vue'
import SectorPerformanceChart from '../components/charts/SectorPerformanceChart.vue'
import TrendChart from '../components/charts/TrendChart.vue'
import { useSortableTable } from '../composables/useSortableTable'

const report = ref(null)
const loading = ref(true)

const sectors = useSortableTable(
  computed(() => report.value?.sector_performance ?? [])
)
const { sorted: sortedSectors } = sectors

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
  report.value = await reportsApi.market()
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <LoadingState v-if="loading" />

    <template v-else-if="report">
      <div class="grid grid-cards">
        <StatCard label="Stocks Tracked" :value="report.totals.stocks" :sub="`${report.totals.with_signals} have a signal`" />
        <StatCard label="Advancing" :value="report.breadth.advancing" tone="positive" />
        <StatCard label="Declining" :value="report.breadth.declining" tone="negative" />
        <StatCard label="Unchanged" :value="report.breadth.unchanged" />
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <Card title="Market Breadth">
          <MarketBreadthChart :breadth="report.breadth" />
        </Card>
        <Card title="Signal Distribution">
          <SignalDistributionChart :counts="report.signal_counts" />
        </Card>
      </div>

      <Card title="Advancing vs. Declining — Last 30 Days" style="margin-top: 16px">
        <TrendChart :labels="trendLabels" :series="breadthSeries" />
      </Card>

      <Card title="Market Turnover — Last 30 Days" style="margin-top: 16px">
        <TrendChart :labels="trendLabels" :series="turnoverSeries" />
      </Card>

      <div class="grid" style="grid-template-columns: 1.3fr 1fr; margin-top: 16px">
        <Card title="Sector Performance (Today)">
          <SectorPerformanceChart :sectors="report.sector_performance" />
        </Card>
        <Card title="Sector Breakdown">
          <table v-align-numbers class="table">
            <thead>
              <tr>
                <SortableTh :table="sectors" column="sector">Sector</SortableTh>
                <SortableTh :table="sectors" column="stock_count">Stocks</SortableTh>
                <SortableTh :table="sectors" column="advancing">Adv/Dec</SortableTh>
                <SortableTh :table="sectors" column="avg_change_pct">Avg %</SortableTh>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in sortedSectors" :key="s.sector">
                <td>
                  <RouterLink v-if="s.sector_id" :to="{ name: 'sector-detail', params: { id: s.sector_id } }">{{ s.sector }}</RouterLink>
                  <RouterLink v-else :to="{ name: 'reports-sector', query: { name: s.sector } }">{{ s.sector }}</RouterLink>
                </td>
                <td>{{ s.stock_count }}</td>
                <td>{{ s.advancing }}/{{ s.declining }}</td>
                <td :class="changeTone(s.avg_change_pct)">{{ s.avg_change_pct !== null ? `${s.avg_change_pct > 0 ? '+' : ''}${s.avg_change_pct}%` : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </Card>
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <Card title="Top Gainers">
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
        <Card title="Top Losers">
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
