<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'
import MarketBreadthChart from '../components/MarketBreadthChart.vue'
import SignalDistributionChart from '../components/SignalDistributionChart.vue'
import SectorPerformanceChart from '../components/SectorPerformanceChart.vue'
import TrendChart from '../components/TrendChart.vue'

const market = ref(null)
const loading = ref(true)

const breadthCaption = computed(() => {
  if (!market.value) return ''
  const { advancing, declining, unchanged } = market.value.breadth
  const total = advancing + declining + unchanged
  const lean = advancing > declining ? 'advancing' : declining > advancing ? 'declining' : 'flat'
  return `Of ${total} stocks with a move today, ${advancing} advanced and ${declining} declined (${unchanged} unchanged) — the market is net ${lean}.`
})

const topSector = computed(() => {
  const sectors = market.value?.sector_performance?.filter((s) => s.avg_change_pct !== null) ?? []
  return sectors.length ? [...sectors].sort((a, b) => b.avg_change_pct - a.avg_change_pct)[0] : null
})

const bottomSector = computed(() => {
  const sectors = market.value?.sector_performance?.filter((s) => s.avg_change_pct !== null) ?? []
  return sectors.length ? [...sectors].sort((a, b) => a.avg_change_pct - b.avg_change_pct)[0] : null
})

const trendLabels = computed(() => market.value?.trend.map((t) => t.trade_date) ?? [])
const turnoverSeries = computed(() => [
  { label: 'Total Turnover (Rs.)', data: market.value?.trend.map((t) => t.total_turnover) ?? [], color: '#2563eb' },
])

async function load() {
  loading.value = true
  const { data } = await client.get('/reports/market')
  market.value = data
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Daily Digest</h1>
    <p class="muted">A visual summary of today's market, generated from the same data behind the Market Report — not AI-written, just templated from real numbers.</p>

    <p v-if="loading" class="muted">Loading…</p>

    <template v-else>
      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Market Breadth</h3>
          <MarketBreadthChart :breadth="market.breadth" />
          <p class="caption muted">{{ breadthCaption }}</p>
        </div>
        <div class="card">
          <h3>Signal Distribution</h3>
          <SignalDistributionChart :counts="market.signal_counts" />
          <p class="caption muted">
            {{ market.signal_counts.strong_buy + market.signal_counts.buy }} stocks flagged Buy or Strong Buy, versus
            {{ market.signal_counts.strong_sell + market.signal_counts.sell }} flagged Sell or Strong Sell — see
            <RouterLink :to="{ name: 'signals-buy' }">Buy Signals</RouterLink> or
            <RouterLink :to="{ name: 'signals-sell' }">Sell Signals</RouterLink> for the full ranked lists.
          </p>
        </div>
      </div>

      <div class="card" style="margin-top: 16px" v-if="topSector">
        <h3>Sector Performance</h3>
        <SectorPerformanceChart :sectors="market.sector_performance" />
        <p class="caption muted">
          <strong>{{ topSector.sector }}</strong> led the market today, up an average of
          {{ topSector.avg_change_pct > 0 ? '+' : '' }}{{ topSector.avg_change_pct }}% across its {{ topSector.stock_count }} stocks.
          <template v-if="bottomSector && bottomSector.sector !== topSector.sector">
            <strong>{{ bottomSector.sector }}</strong> lagged, averaging {{ bottomSector.avg_change_pct }}% across its {{ bottomSector.stock_count }} stocks.
          </template>
        </p>
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>Market Turnover — Last 30 Days</h3>
        <TrendChart :labels="trendLabels" :series="turnoverSeries" />
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <RouterLink
          v-if="market.movers.gainers[0]"
          :to="{ name: 'stock-detail', params: { symbol: market.movers.gainers[0].symbol } }"
          class="card mover-card positive"
        >
          <p class="label">Day's Biggest Gainer</p>
          <p class="mover-symbol">{{ market.movers.gainers[0].symbol }}</p>
          <p class="mover-detail">+{{ market.movers.gainers[0].change_pct }}% · Rs. {{ formatPrice(market.movers.gainers[0].close) }}</p>
        </RouterLink>
        <RouterLink
          v-if="market.movers.losers[0]"
          :to="{ name: 'stock-detail', params: { symbol: market.movers.losers[0].symbol } }"
          class="card mover-card negative"
        >
          <p class="label">Day's Biggest Loser</p>
          <p class="mover-symbol">{{ market.movers.losers[0].symbol }}</p>
          <p class="mover-detail">{{ market.movers.losers[0].change_pct }}% · Rs. {{ formatPrice(market.movers.losers[0].close) }}</p>
        </RouterLink>
      </div>

      <p class="muted small" style="margin-top: 16px">Not financial advice — a summary of today's data, not a recommendation.</p>
    </template>
  </div>
</template>

<style scoped>
.caption {
  margin: 10px 0 0;
  font-size: 0.88rem;
  line-height: 1.6;
}

.small {
  font-size: 0.78rem;
}

.mover-card {
  text-decoration: none;
  color: inherit;
  display: block;
  border-left: 4px solid transparent;
  transition: box-shadow 0.15s, border-color 0.15s;
}

.mover-card:hover {
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
}

.mover-card.positive {
  border-left-color: var(--strong-buy);
}

.mover-card.negative {
  border-left-color: var(--strong-sell);
}

.mover-card .label {
  margin: 0 0 6px;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--text-muted);
  font-weight: 600;
}

.mover-symbol {
  margin: 0;
  font-size: 1.5rem;
  font-weight: 700;
}

.mover-detail {
  margin: 4px 0 0;
  font-size: 0.9rem;
  color: var(--text-muted);
}

.mover-card.positive .mover-detail {
  color: var(--strong-buy);
}

.mover-card.negative .mover-detail {
  color: var(--strong-sell);
}
</style>
