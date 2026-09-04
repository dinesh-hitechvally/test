<script setup>
import { computed } from 'vue'
import { Chart } from 'vue-chartjs'
import { Chart as ChartJS, ScatterController, LinearScale, PointElement, Tooltip } from 'chart.js'
import { toPointFigure } from '../utils/chartTransforms'

ChartJS.register(ScatterController, LinearScale, PointElement, Tooltip)

const props = defineProps({
  prices: { type: Array, required: true },
})

const pf = computed(() => toPointFigure(props.prices))

const xPoints = computed(() => {
  const points = []
  pf.value.columns.forEach((col, colIndex) => {
    if (col.type !== 'X') return
    col.boxes.forEach((box) => points.push({ x: colIndex, y: box * pf.value.boxSize }))
  })
  return points
})

const oPoints = computed(() => {
  const points = []
  pf.value.columns.forEach((col, colIndex) => {
    if (col.type !== 'O') return
    col.boxes.forEach((box) => points.push({ x: colIndex, y: box * pf.value.boxSize }))
  })
  return points
})

const chartData = computed(() => ({
  datasets: [
    { label: 'X (rising)', data: xPoints.value, backgroundColor: '#15803d', pointStyle: 'crossRot', radius: 6, borderWidth: 2, borderColor: '#15803d' },
    { label: 'O (falling)', data: oPoints.value, backgroundColor: 'transparent', pointStyle: 'circle', radius: 6, borderWidth: 2, borderColor: '#b91c1c' },
  ],
}))

const options = {
  responsive: true,
  maintainAspectRatio: false,
  scales: {
    x: { title: { display: true, text: 'Column #' }, ticks: { stepSize: 1 } },
    y: { position: 'right', ticks: { callback: (v) => `Rs. ${v}` } },
  },
  plugins: {
    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
    tooltip: { callbacks: { label: (ctx) => `Rs. ${ctx.parsed.y.toFixed(2)}` } },
  },
}

const hasData = computed(() => xPoints.value.length + oPoints.value.length > 0)
</script>

<template>
  <div>
    <p class="muted note">Box size Rs. {{ pf.boxSize.toFixed(2) }}, 3-box reversal — columns are sequential, not aligned to real dates.</p>
    <div style="height: 400px">
      <Chart v-if="hasData" type="scatter" :data="chartData" :options="options" />
      <p v-else class="muted" style="padding-top: 160px; text-align: center">Not enough price movement yet to form a column.</p>
    </div>
  </div>
</template>

<style scoped>
.note {
  font-size: 0.78rem;
  margin: 0 0 8px;
}
</style>
