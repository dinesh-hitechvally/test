<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'

const market = ref(null)
const loading = ref(true)

const breadthSentence = computed(() => {
  if (!market.value) return ''
  const { advancing, declining, unchanged } = market.value.breadth
  const total = advancing + declining + unchanged
  const lean = advancing > declining ? 'advancing' : declining > advancing ? 'declining' : 'flat'
  return `Of ${total} stocks with a move today, ${advancing} advanced and ${declining} declined (${unchanged} unchanged) — the market is net ${lean}.`
})

const topSector = computed(() => {
  const sectors = market.value?.sector_performance?.filter((s) => s.avg_change_pct !== null) ?? []
  if (!sectors.length) return null
  return [...sectors].sort((a, b) => b.avg_change_pct - a.avg_change_pct)[0]
})

const bottomSector = computed(() => {
  const sectors = market.value?.sector_performance?.filter((s) => s.avg_change_pct !== null) ?? []
  if (!sectors.length) return null
  return [...sectors].sort((a, b) => a.avg_change_pct - b.avg_change_pct)[0]
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
    <h1>Daily Digest</h1>
    <p class="muted">A plain-language summary of today's market, generated from the same data behind the Market Report — not AI-written, just templated from real numbers.</p>

    <p v-if="loading" class="muted">Loading…</p>

    <div v-else class="card digest">
      <p>{{ breadthSentence }}</p>

      <p v-if="topSector">
        <strong>{{ topSector.sector }}</strong> led the market today, up an average of
        {{ topSector.avg_change_pct > 0 ? '+' : '' }}{{ topSector.avg_change_pct }}% across its {{ topSector.stock_count }} stocks.
      </p>
      <p v-if="bottomSector && bottomSector.sector !== topSector?.sector">
        <strong>{{ bottomSector.sector }}</strong> lagged, averaging {{ bottomSector.avg_change_pct }}% across its {{ bottomSector.stock_count }} stocks.
      </p>

      <p v-if="market.movers.gainers.length">
        The day's biggest gainer was
        <RouterLink :to="{ name: 'stock-detail', params: { symbol: market.movers.gainers[0].symbol } }">{{ market.movers.gainers[0].symbol }}</RouterLink>,
        up {{ market.movers.gainers[0].change_pct }}% to Rs. {{ formatPrice(market.movers.gainers[0].close) }}.
      </p>
      <p v-if="market.movers.losers.length">
        The biggest loser was
        <RouterLink :to="{ name: 'stock-detail', params: { symbol: market.movers.losers[0].symbol } }">{{ market.movers.losers[0].symbol }}</RouterLink>,
        down {{ market.movers.losers[0].change_pct }}% to Rs. {{ formatPrice(market.movers.losers[0].close) }}.
      </p>

      <p>
        {{ market.signal_counts.strong_buy + market.signal_counts.buy }} stocks are currently flagged Buy or Strong Buy, versus
        {{ market.signal_counts.strong_sell + market.signal_counts.sell }} flagged Sell or Strong Sell — see
        <RouterLink :to="{ name: 'signals-buy' }">Buy Signals</RouterLink> or
        <RouterLink :to="{ name: 'signals-sell' }">Sell Signals</RouterLink> for the full ranked lists.
      </p>

      <p class="muted small">Not financial advice — a summary of today's data, not a recommendation.</p>
    </div>
  </div>
</template>

<style scoped>
.digest p {
  line-height: 1.7;
  font-size: 0.95rem;
}

.small {
  font-size: 0.78rem;
}
</style>
