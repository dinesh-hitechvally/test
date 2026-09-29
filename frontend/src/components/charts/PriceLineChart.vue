<script setup>
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import { Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip } from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip)

const props = defineProps({
  prices: { type: Array, required: true }, // [{ trade_date, close_price }]
  area: { type: Boolean, default: false },
})

const chartData = computed(() => ({
  labels: props.prices.map((p) => p.trade_date),
  datasets: [
    {
      label: 'Close',
      data: props.prices.map((p) => Number(p.close_price)),
      borderColor: '#2563eb',
      backgroundColor: props.area ? 'rgba(37, 99, 235, 0.15)' : 'transparent',
      fill: props.area,
      pointRadius: 0,
      borderWidth: 2,
    },
  ],
}))

const options = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  scales: { x: { ticks: { maxTicksLimit: 10 } } },
  plugins: { legend: { display: false } },
}

const hasData = computed(() => props.prices.length > 0)
</script>

<template>
  <div style="height: 420px">
    <Line v-if="hasData" :data="chartData" :options="options" />
    <p v-else class="muted" style="padding-top: 180px; text-align: center">No price history yet.</p>
  </div>
</template>
