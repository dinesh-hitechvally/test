<script setup>
import { computed, onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import { useSortableTable } from '../composables/useSortableTable'

const matches = ref([])
const loading = ref(true)
const filter = ref('')

const filtered = computed(() => (filter.value ? matches.value.filter((m) => m.signal === filter.value) : matches.value))
const table = useSortableTable(filtered)
const { sorted } = table

function biasClass(signal) {
  return signal === 'bullish' ? 'buy' : signal === 'bearish' ? 'sell' : 'hold'
}

async function load() {
  loading.value = true
  matches.value = await marketApi.candlestickPatterns()
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <PageHeader title="Chart Patterns">
      Every stock whose most recent candle forms a recognizable pattern (Doji, Hammer, Shooting Star, or an
      Engulfing pattern) — a lighter, faster scan than the deeper per-stock pattern detection on the
      Technical Analysis report, run across the whole market.
    </PageHeader>

    <div class="filters">
      <button class="btn-secondary btn" :class="{ active: filter === '' }" @click="filter = ''">All</button>
      <button class="btn-secondary btn" :class="{ active: filter === 'bullish' }" @click="filter = 'bullish'">Bullish</button>
      <button class="btn-secondary btn" :class="{ active: filter === 'bearish' }" @click="filter = 'bearish'">Bearish</button>
      <button class="btn-secondary btn" :class="{ active: filter === 'neutral' }" @click="filter = 'neutral'">Neutral</button>
    </div>

    <LoadingState v-if="loading" style="margin-top: 12px" />

    <div v-else class="card" style="margin-top: 12px">
      <table v-align-numbers class="table">
        <thead>
          <tr>
            <SortableTh :table="table" column="symbol">Symbol</SortableTh>
            <SortableTh :table="table" column="company_name">Company</SortableTh>
            <SortableTh :table="table" column="trade_date">Date</SortableTh>
            <SortableTh :table="table" column="pattern">Pattern</SortableTh>
            <SortableTh :table="table" column="signal">Bias</SortableTh>
          </tr>
        </thead>
        <tbody>
          <tr v-for="m in sorted" :key="m.stock_id">
            <td><StockLink :symbol="m.symbol" /></td>
            <td class="muted">{{ m.company_name }}</td>
            <td>{{ m.trade_date }}</td>
            <td><strong>{{ m.pattern }}</strong></td>
            <td><span class="badge" :class="biasClass(m.signal)">{{ m.signal }}</span></td>
          </tr>
          <tr v-if="filtered.length === 0">
            <td colspan="5" class="muted" style="text-align: center; padding: 24px">No matches right now.</td>
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
}

.filters .active {
  background: #1e293b;
  color: #fff;
  border-color: #1e293b;
}
</style>
