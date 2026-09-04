<script setup>
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import { Chart as ChartJS, TimeScale, LinearScale, PointElement, LineElement, Tooltip } from 'chart.js'
import 'chartjs-adapter-date-fns'
import { toKagiPoints } from '../utils/chartTransforms'

ChartJS.register(TimeScale, LinearScale, PointElement, LineElement, Tooltip)

const props = defineProps({
  prices: { type: Array, required: true },
})

const points = computed(() => toKagiPoints(props.prices).map((p) => ({ x: new Date(p.x).getTime(), y: p.y })))

const chartData = computed(() => ({
  datasets: [
    {
      label: 'Kagi',
      data: points.value,
      borderWidth: 2.5,
      pointRadius: 0,
      backgroundColor: 'transparent',
      // Traditional Kagi coloring: thick/green ("yang") while a segment is
      // above the prior shoulder, thin/red ("yin") while below it — approximated
      // here by whether each segment is rising or falling.
      segment: {
        borderColor: (ctx) => (ctx.p1.parsed.y >= ctx.p0.parsed.y ? '#15803d' : '#b91c1c'),
      },
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

const hasData = computed(() => points.value.length > 1)
</script>

<template>
  <div>
    <p class="muted note">Simplified Kagi — a new line starts once price reverses ~3% from its last turning point.</p>
    <div style="height: 400px">
      <Line v-if="hasData" :data="chartData" :options="options" />
      <p v-else class="muted" style="padding-top: 160px; text-align: center">Not enough price movement yet to form a Kagi line.</p>
    </div>
  </div>
</template>

<style scoped>
.note {
  font-size: 0.78rem;
  margin: 0 0 8px;
}
</style>
