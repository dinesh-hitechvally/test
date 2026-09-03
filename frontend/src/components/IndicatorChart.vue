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
  indicators: { type: Array, default: () => [] },
  // series: [{ field: 'rsi_14', label: 'RSI 14', color: '#2563eb' }, ...]
  series: { type: Array, required: true },
  yMin: { type: Number, default: undefined },
  yMax: { type: Number, default: undefined },
})

const chartData = computed(() => ({
  labels: props.indicators.map((i) => i.trade_date),
  datasets: props.series.map((s) => ({
    label: s.label,
    data: props.indicators.map((i) => i[s.field]),
    borderColor: s.color,
    backgroundColor: 'transparent',
    pointRadius: 0,
    borderWidth: 1.5,
  })),
}))

const options = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  scales: {
    x: { ticks: { maxTicksLimit: 8 } },
    y: { min: props.yMin, max: props.yMax },
  },
}))
</script>

<template>
  <div style="height: 160px">
    <Line :data="chartData" :options="options" />
  </div>
</template>
