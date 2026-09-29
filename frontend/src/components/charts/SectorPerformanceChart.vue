<script setup>
import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import { Chart as ChartJS, BarElement, CategoryScale, LinearScale, Tooltip } from 'chart.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip)

const props = defineProps({
  sectors: { type: Array, required: true }, // [{ sector, avg_change_pct }]
})

const chartData = computed(() => {
  const sorted = [...props.sectors]
    .filter((s) => s.avg_change_pct !== null)
    .sort((a, b) => b.avg_change_pct - a.avg_change_pct)

  return {
    labels: sorted.map((s) => s.sector),
    datasets: [
      {
        data: sorted.map((s) => s.avg_change_pct),
        backgroundColor: sorted.map((s) => (s.avg_change_pct >= 0 ? '#15803d' : '#b91c1c')),
        borderRadius: 4,
      },
    ],
  }
})

const options = {
  indexAxis: 'y',
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: { callbacks: { label: (ctx) => `${ctx.parsed.x > 0 ? '+' : ''}${ctx.parsed.x}%` } },
  },
  scales: { x: { ticks: { callback: (v) => `${Number(v).toFixed(2)}%` } } },
}

const hasData = computed(() => props.sectors.some((s) => s.avg_change_pct !== null))
</script>

<template>
  <div style="height: 320px">
    <Bar v-if="hasData" :data="chartData" :options="options" />
    <p v-else class="muted" style="padding-top: 120px; text-align: center">Not enough data yet.</p>
  </div>
</template>
