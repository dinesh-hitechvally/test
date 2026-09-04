<script setup>
import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import { Chart as ChartJS, BarElement, CategoryScale, LinearScale, Tooltip } from 'chart.js'
import { toRenko } from '../utils/chartTransforms'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip)

const props = defineProps({
  prices: { type: Array, required: true },
})

const renko = computed(() => toRenko(props.prices))

const chartData = computed(() => ({
  labels: renko.value.bricks.map((b) => b.index + 1),
  datasets: [
    {
      label: 'Renko',
      data: renko.value.bricks.map((b) => [b.low, b.high]),
      backgroundColor: renko.value.bricks.map((b) => (b.up ? '#15803d' : '#b91c1c')),
      barPercentage: 0.9,
      categoryPercentage: 1,
    },
  ],
}))

const options = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  scales: {
    x: { ticks: { maxTicksLimit: 12 }, title: { display: true, text: 'Brick #' } },
    y: { position: 'right' },
  },
  plugins: {
    legend: { display: false },
    tooltip: { callbacks: { label: (ctx) => `Rs. ${ctx.raw[0].toFixed(2)} – ${ctx.raw[1].toFixed(2)}` } },
  },
}))

const hasData = computed(() => renko.value.bricks.length > 0)
</script>

<template>
  <div>
    <p class="muted note">Box size Rs. {{ renko.boxSize.toFixed(2) }} (~1.5% of average close) — bricks are sequential, not aligned to real dates.</p>
    <div style="height: 400px">
      <Bar v-if="hasData" :data="chartData" :options="options" />
      <p v-else class="muted" style="padding-top: 160px; text-align: center">Not enough price history to form a brick yet.</p>
    </div>
  </div>
</template>

<style scoped>
.note {
  font-size: 0.78rem;
  margin: 0 0 8px;
}
</style>
