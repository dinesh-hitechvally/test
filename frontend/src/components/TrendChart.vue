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

const props = defineProps({
  labels: { type: Array, required: true },
  series: { type: Array, required: true }, // [{ label, data: number[], color }]
  height: { type: Number, default: 280 },
})

const chartData = computed(() => ({
  labels: props.labels,
  datasets: props.series.map((s) => ({
    label: s.label,
    data: s.data,
    borderColor: s.color,
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
  scales: { x: { ticks: { maxTicksLimit: 8 } } },
  plugins: {
    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
  },
}

const hasData = computed(() => props.labels.length > 0)
</script>

<template>
  <div :style="{ height: `${height}px` }">
    <Line v-if="hasData" :data="chartData" :options="options" />
    <p v-else class="muted" style="padding-top: 100px; text-align: center">Not enough data yet.</p>
  </div>
</template>
