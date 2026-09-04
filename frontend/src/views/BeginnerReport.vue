<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'

const market = ref(null)
const loading = ref(true)

const items = computed(() => {
  if (!market.value) return []
  const m = market.value
  const list = []

  const lean = m.breadth.advancing > m.breadth.declining ? 'more stocks rose than fell' : m.breadth.declining > m.breadth.advancing ? 'more stocks fell than rose' : 'the market was evenly split'
  list.push(`Today, ${lean} — ${m.breadth.advancing} up, ${m.breadth.declining} down, out of ${m.totals.stocks} tracked stocks.`)

  const topSector = [...(m.sector_performance || [])].filter((s) => s.avg_change_pct !== null).sort((a, b) => b.avg_change_pct - a.avg_change_pct)[0]
  if (topSector) list.push(`The ${topSector.sector} sector was the strongest today, averaging ${topSector.avg_change_pct > 0 ? '+' : ''}${topSector.avg_change_pct}%.`)

  if (m.movers.gainers[0]) list.push(`${m.movers.gainers[0].symbol} was today's top gainer, up ${m.movers.gainers[0].change_pct}% to Rs. ${formatPrice(m.movers.gainers[0].close)}.`)
  if (m.movers.losers[0]) list.push(`${m.movers.losers[0].symbol} was today's biggest loser, down ${Math.abs(m.movers.losers[0].change_pct)}% to Rs. ${formatPrice(m.movers.losers[0].close)}.`)

  const buyCount = m.signal_counts.strong_buy + m.signal_counts.buy
  list.push(`${buyCount} stocks are currently showing a Buy or Strong Buy signal — worth a look if you're hunting for ideas, but always check the reasons behind a signal before acting.`)

  return list
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
    <p class="muted">The 5 things worth knowing about today's market, in plain language. New here? Start with <RouterLink :to="{ name: 'learn-basics' }">Stock Market Basics</RouterLink>.</p>

    <p v-if="loading" class="muted">Loading…</p>

    <ol v-else class="digest-list">
      <li v-for="(item, i) in items" :key="i">{{ item }}</li>
    </ol>

    <p class="muted small">Not financial advice — a plain-language summary of today's numbers, not a recommendation to buy or sell anything.</p>
  </div>
</template>

<style scoped>
.digest-list {
  padding-left: 22px;
}

.digest-list li {
  margin-bottom: 14px;
  line-height: 1.6;
  font-size: 0.95rem;
}

.small {
  font-size: 0.78rem;
}
</style>
