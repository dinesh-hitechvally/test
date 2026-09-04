<script setup>
import { onMounted, ref } from 'vue'
import client from '../api/client'
import StatCard from '../components/StatCard.vue'
import TrendChart from '../components/TrendChart.vue'

const indices = ref([])
const loading = ref(true)

const COLORS = ['#2563eb', '#dc2626', '#15803d', '#d97706']

function changeTone(value) {
  if (value === null || value === undefined) return ''
  return value > 0 ? 'positive' : value < 0 ? 'negative' : ''
}

async function load() {
  loading.value = true
  const { data } = await client.get('/indices')
  indices.value = data
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Indices</h1>
    <p class="muted">
      NEPSE Index and sub-indices from the official nepalstock.com API. Trend history only starts accumulating from
      when this was first tracked — no official source offers historical index backfill, so early on this chart will
      be short and grow day by day.
    </p>

    <p v-if="loading" class="muted">Loading…</p>

    <template v-else>
      <div class="grid grid-cards">
        <StatCard
          v-for="idx in indices"
          :key="idx.index_name"
          :label="idx.index_name"
          :value="Number(idx.latest.close).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })"
          :tone="changeTone(Number(idx.latest.change_pct))"
          :sub="idx.latest.change_pct !== null ? `${Number(idx.latest.change_pct) > 0 ? '+' : ''}${idx.latest.change_pct}%` : ''"
        />
      </div>

      <div class="card" style="margin-top: 16px" v-for="idx in indices" :key="`chart-${idx.index_name}`">
        <h3>{{ idx.index_name }}</h3>
        <TrendChart
          :labels="idx.history.map((h) => h.trade_date)"
          :series="[{ label: idx.index_name, data: idx.history.map((h) => h.close), color: COLORS[indices.indexOf(idx) % COLORS.length] }]"
          :height="220"
        />
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
