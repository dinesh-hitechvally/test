<script setup>
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useStocksStore } from '../stores/stocks'
import MarketTable from '../components/MarketTable.vue'

const route = useRoute()
const store = useStocksStore()

const PRESETS = {
  gainers: { title: 'Gainers', sort: { key: 'change_pct', dir: 'desc' }, showTurnoverVolume: false },
  losers: { title: 'Losers', sort: { key: 'change_pct', dir: 'asc' }, showTurnoverVolume: false },
  turnover: { title: 'Turnover', sort: { key: 'turnover', dir: 'desc' }, showTurnoverVolume: true },
  volume: { title: 'Volume', sort: { key: 'volume', dir: 'desc' }, showTurnoverVolume: true },
}

const preset = computed(() => PRESETS[route.meta.preset] || PRESETS.gainers)

onMounted(() => {
  if (store.stocks.length === 0) store.fetchStocks()
})
</script>

<template>
  <div>
    <h1>{{ preset.title }}</h1>
    <MarketTable
      :key="route.meta.preset"
      :stocks="store.stocks"
      :default-sort="preset.sort"
      :show-turnover-volume="preset.showTurnoverVolume"
    />
  </div>
</template>
