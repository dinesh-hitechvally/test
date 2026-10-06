<script setup>
import { onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import { changeTone } from '../utils/format'
import TrendChart from '../components/charts/TrendChart.vue'

const indices = ref([])
const loading = ref(true)

const COLORS = ['#2563eb', '#dc2626', '#15803d', '#d97706']

async function load() {
  loading.value = true
  indices.value = await marketApi.indices()
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <p class="muted">
      NEPSE Index and sub-indices from the official nepalstock.com API. Trend history only starts accumulating from
      when this was first tracked — no official source offers historical index backfill, so early on this chart will
      be short and grow day by day.
    </p>

    <LoadingState v-if="loading" />

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

      <Card :title="idx.index_name" style="margin-top: 16px" v-for="idx in indices" :key="`chart-${idx.index_name}`">
        <TrendChart
          :labels="idx.history.map((h) => h.trade_date)"
          :series="[{ label: idx.index_name, data: idx.history.map((h) => h.close), color: COLORS[indices.indexOf(idx) % COLORS.length] }]"
          :height="220"
        />
      </Card>
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
