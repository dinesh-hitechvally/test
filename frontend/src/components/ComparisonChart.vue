<script setup>
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Tooltip,
  Legend,
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend)

const COLORS = ['#2563eb', '#dc2626', '#15803d', '#d97706', '#7c3aed', '#0891b2']

const props = defineProps({
  labels: { type: Array, required: true },
  series: { type: Array, required: true }, // [{ symbol, data: number|null[] }]
})

const chartData = computed(() => ({
  labels: props.labels,
  datasets: props.series.map((s, i) => ({
    label: s.symbol,
    data: s.data,
    borderColor: COLORS[i % COLORS.length],
    backgroundColor: 'transparent',
    pointRadius: 0,
    borderWidth: 2,
    spanGaps: true,
  })),
}))

const options = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  scales: {
    x: { ticks: { maxTicksLimit: 10 } },
    y: { ticks: { callback: (v) => `${v}%` } },
  },
  plugins: {
    tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y === null ? '—' : ctx.parsed.y.toFixed(2) + '%'}` } },
  },
}
</script>

<template>
  <div style="height: 360px">
    <Line :data="chartData" :options="options" />
  </div>
</template>
