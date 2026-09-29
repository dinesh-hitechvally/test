<script setup>
import { computed } from 'vue'
import { Chart } from 'vue-chartjs'
import { Chart as ChartJS, TimeScale, LinearScale, Tooltip, Legend } from 'chart.js'
import { OhlcController, OhlcElement } from 'chartjs-chart-financial'
import 'chartjs-adapter-date-fns'

ChartJS.register(TimeScale, LinearScale, Tooltip, Legend, OhlcController, OhlcElement)

const props = defineProps({
  prices: { type: Array, required: true },
})

const chartData = computed(() => ({
  datasets: [
    {
      label: 'Price',
      data: props.prices.map((p) => ({
        x: new Date(p.trade_date).getTime(),
        o: Number(p.open_price),
        h: Number(p.high_price),
        l: Number(p.low_price),
        c: Number(p.close_price),
      })),
      color: { up: '#15803d', down: '#b91c1c', unchanged: '#94a3b8' },
    },
  ],
}))

const options = {
  responsive: true,
  maintainAspectRatio: false,
  scales: {
    x: { type: 'time', time: { unit: 'day' }, ticks: { maxTicksLimit: 10 } },
    y: { position: 'right' },
  },
  plugins: { legend: { display: false } },
}

const hasData = computed(() => props.prices.length > 0)
</script>

<template>
  <div style="height: 420px">
    <Chart v-if="hasData" type="ohlc" :data="chartData" :options="options" />
    <p v-else class="muted" style="padding-top: 180px; text-align: center">No price history yet.</p>
  </div>
</template>
