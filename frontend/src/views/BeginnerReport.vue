<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'
import MarketBreadthChart from '../components/MarketBreadthChart.vue'
import SignalDistributionChart from '../components/SignalDistributionChart.vue'
import SectorPerformanceChart from '../components/SectorPerformanceChart.vue'

const market = ref(null)
const loading = ref(true)

const breadthCaption = computed(() => {
  if (!market.value) return ''
  const { advancing, declining } = market.value.breadth
  const lean = advancing > declining ? 'more stocks rose than fell' : declining > advancing ? 'more stocks fell than rose' : 'the market was evenly split'
  return `Today, ${lean} — ${advancing} up, ${declining} down, out of ${market.value.totals.stocks} tracked stocks.`
})

const topSector = computed(() => {
  const sectors = market.value?.sector_performance?.filter((s) => s.avg_change_pct !== null) ?? []
  return sectors.length ? [...sectors].sort((a, b) => b.avg_change_pct - a.avg_change_pct)[0] : null
})

const buyCount = computed(() => {
  if (!market.value) return 0
  return market.value.signal_counts.strong_buy + market.value.signal_counts.buy
})

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
    <h1>Beginner's Report</h1>
    <p class="muted">
      The 5 things worth knowing about today's market, at a glance. New here? Start with
      <RouterLink :to="{ name: 'learn-basics' }">Stock Market Basics</RouterLink>.
    </p>

    <p v-if="loading" class="muted">Loading…</p>

    <template v-else>
      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Who's Winning Today</h3>
          <MarketBreadthChart :breadth="market.breadth" />
          <p class="caption muted">{{ breadthCaption }}</p>
        </div>
        <div class="card">
          <h3>Buy vs. Sell Signals</h3>
          <SignalDistributionChart :counts="market.signal_counts" />
          <p class="caption muted">
            {{ buyCount }} stocks are currently showing a Buy or Strong Buy signal — worth a look if you're hunting
            for ideas, but always check the reasons behind a signal before acting.
          </p>
        </div>
      </div>

      <div class="card" style="margin-top: 16px" v-if="topSector">
        <h3>Sector Performance Today</h3>
        <SectorPerformanceChart :sectors="market.sector_performance" />
        <p class="caption muted">
          <strong>{{ topSector.sector }}</strong> was the strongest sector today, averaging
          {{ topSector.avg_change_pct > 0 ? '+' : '' }}{{ topSector.avg_change_pct }}%.
        </p>
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <RouterLink
          v-if="market.movers.gainers[0]"
          :to="{ name: 'stock-detail', params: { symbol: market.movers.gainers[0].symbol } }"
          class="card mover-card positive"
        >
          <p class="label">Today's Top Gainer</p>
          <p class="mover-symbol">{{ market.movers.gainers[0].symbol }}</p>
          <p class="mover-detail">+{{ market.movers.gainers[0].change_pct }}% · Rs. {{ formatPrice(market.movers.gainers[0].close) }}</p>
        </RouterLink>
        <RouterLink
          v-if="market.movers.losers[0]"
          :to="{ name: 'stock-detail', params: { symbol: market.movers.losers[0].symbol } }"
          class="card mover-card negative"
        >
          <p class="label">Today's Biggest Loser</p>
          <p class="mover-symbol">{{ market.movers.losers[0].symbol }}</p>
          <p class="mover-detail">{{ market.movers.losers[0].change_pct }}% · Rs. {{ formatPrice(market.movers.losers[0].close) }}</p>
        </RouterLink>
      </div>

      <p class="muted small" style="margin-top: 16px">
        Not financial advice — a plain-language summary of today's numbers, not a recommendation to buy or sell anything.
      </p>
    </template>
  </div>
</template>

<style scoped>
.caption {
  margin: 10px 0 0;
  font-size: 0.88rem;
  line-height: 1.5;
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
