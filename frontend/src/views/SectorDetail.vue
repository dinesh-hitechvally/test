<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import * as reportsApi from '../api/reports'
import { useColumnOptions } from '../composables/useColumnOptions'
import { usePageTitle } from '../composables/usePageTitle'
import { changeTone } from '../utils/format'
import SignalDistributionChart from '../components/charts/SignalDistributionChart.vue'
import TrendChart from '../components/charts/TrendChart.vue'
import MarketTable from '../components/stock/MarketTable.vue'

const route = useRoute()

const report = ref(null)

// The sector's name goes in the top bar rather than in a heading on the page.
usePageTitle(() => report.value?.sector ?? '')
const loading = ref(true)
const error = ref('')
const notFound = ref(false)

// Screen Options for the stock table below (the sector is the page itself, so no Sector column).
const columns = useColumnOptions('sector-detail', [
  { key: 'symbol', label: 'Symbol', locked: true },
  { key: 'company_name', label: 'Company' },
  { key: 'last_close', label: 'Last Close' },
  { key: 'high', label: 'High' },
  { key: 'low', label: 'Low' },
  { key: 'change_pct', label: '% Change' },
  { key: 'turnover', label: 'Turnover' },
  { key: 'volume', label: 'Volume' },
  { key: 'signal', label: 'Signal' },
])

const trendLabels = computed(() => report.value?.trend.map((t) => t.trade_date) ?? [])
const breadthSeries = computed(() => [
  { label: 'Advancing', data: report.value?.trend.map((t) => t.advancing) ?? [], color: '#15803d' },
  { label: 'Declining', data: report.value?.trend.map((t) => t.declining) ?? [], color: '#b91c1c' },
])

function formatChange(pct) {
  return pct === null || pct === undefined ? '—' : `${pct > 0 ? '+' : ''}${pct}%`
}

let latest = 0
async function load() {
  const request = ++latest
  loading.value = true
  error.value = ''
  notFound.value = false
  try {
    const data = await reportsApi.sectorDetail(Number(route.params.id))
    if (request === latest) report.value = data
  } catch (e) {
    if (request !== latest) return
    report.value = null
    if (e.response?.status === 404) notFound.value = true
    else error.value = e.response?.data?.message || 'Could not load this sector.'
  } finally {
    if (request === latest) loading.value = false
  }
}

// Moving between /sectors/1 and /sectors/2 re-uses this page, so reload on a new id.
watch(() => route.params.id, load)
onMounted(load)
</script>

<template>
  <div>
    <ScreenOptions
      title="Stock table columns"
      :options="columns.options"
      :visible="columns.visibleKeys.value"
      note="Choose which columns the stock table shows. Your choice is saved in this browser."
      @toggle="columns.toggle"
      @reset="columns.reset"
    />

    <p class="back"><RouterLink :to="{ name: 'market-sector-list' }">← Sector List</RouterLink></p>

    <LoadingState v-if="loading && !report" />

    <Card v-else-if="notFound">
      <EmptyState>There is no sector with id {{ route.params.id }}. <RouterLink :to="{ name: 'market-sector-list' }">Back to the sector list</RouterLink>.</EmptyState>
    </Card>

    <p v-else-if="error" class="error-text">{{ error }}</p>

    <template v-else-if="report">
      <div class="grid grid-cards">
        <StatCard label="Stocks" :value="report.totals.stock_count" />
        <StatCard label="Advancing" :value="report.totals.advancing" tone="positive" />
        <StatCard label="Declining" :value="report.totals.declining" tone="negative" />
        <StatCard label="Avg Change" :value="formatChange(report.avg_change_pct)" :tone="changeTone(report.avg_change_pct, 'neutral')" />
      </div>

      <EmptyState v-if="report.totals.stock_count === 0" style="margin-top: 16px">No stocks are assigned to this sector yet.</EmptyState>

      <template v-else>
        <div class="grid two" style="margin-top: 16px">
          <Card title="Signal Distribution">
            <SignalDistributionChart :counts="report.signal_counts" />
          </Card>
          <Card title="Advancing vs. Declining — Last 30 Days">
            <TrendChart :labels="trendLabels" :series="breadthSeries" />
          </Card>
        </div>

        <div class="grid two" style="margin-top: 16px">
          <Card title="Top Gainers">
            <table v-align-numbers class="table">
              <tbody>
                <tr v-for="s in report.top_gainers" :key="s.id">
                  <td><StockLink :symbol="s.symbol" /></td>
                  <td :class="changeTone(s.change_pct)">{{ formatChange(s.change_pct) }}</td>
                </tr>
              </tbody>
            </table>
            <EmptyState v-if="report.top_gainers.length === 0">Not enough data yet.</EmptyState>
          </Card>
          <Card title="Top Losers">
            <table v-align-numbers class="table">
              <tbody>
                <tr v-for="s in report.top_losers" :key="s.id">
                  <td><StockLink :symbol="s.symbol" /></td>
                  <td :class="changeTone(s.change_pct)">{{ formatChange(s.change_pct) }}</td>
                </tr>
              </tbody>
            </table>
            <EmptyState v-if="report.top_losers.length === 0">Not enough data yet.</EmptyState>
          </Card>
        </div>

        <Card :title="`Stocks in ${report.sector}`" style="margin-top: 16px">
          <MarketTable :stocks="report.stocks" :columns="columns.visibleKeys.value" :show-turnover-volume="true" />
        </Card>
      </template>
    </template>
  </div>
</template>

<style scoped>
.back {
  margin: 0 0 8px;
  font-size: 0.9rem;
}

.grid.two {
  grid-template-columns: 1fr 1fr;
}

@media (max-width: 800px) {
  .grid.two {
    grid-template-columns: 1fr;
  }
}
</style>
