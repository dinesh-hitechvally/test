<script setup>
import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import { Chart as ChartJS, BarElement, CategoryScale, LinearScale, Tooltip } from 'chart.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip)

const props = defineProps({
  sectors: { type: Array, required: true }, // [{ sector, stock_count, advancing, declining, avg_change_pct, total_turnover }]
})

const POSITIVE = '#16a34a'
const NEGATIVE = '#dc2626'
const NEUTRAL = '#94a3b8'

const chartData = computed(() => {
  // Today's real move per sector, not a stock-count tally — biggest movers
  // (either direction) surface first, same as a "sector heat" read should.
  const top = [...props.sectors]
    .filter((s) => s.avg_change_pct !== null)
    .sort((a, b) => Math.abs(b.avg_change_pct) - Math.abs(a.avg_change_pct))
    .slice(0, 8)
    .reverse() // horizontal bar chart reads top-to-bottom as first-to-last, flip so the biggest mover is on top

  return {
    labels: top.map((s) => s.sector),
    datasets: [
      {
        data: top.map((s) => s.avg_change_pct),
        backgroundColor: top.map((s) => (s.avg_change_pct > 0 ? POSITIVE : s.avg_change_pct < 0 ? NEGATIVE : NEUTRAL)),
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
    tooltip: {
      callbacks: {
        label: (ctx) => `${ctx.parsed.x > 0 ? '+' : ''}${ctx.parsed.x}% avg today`,
      },
    },
  },
  scales: {
    x: {
      ticks: { callback: (v) => `${v > 0 ? '+' : ''}${v}%` },
    },
  },
}
</script>

<template>
  <div style="height: 220px">
    <Bar v-if="chartData.labels.length" :data="chartData" :options="options" />
    <p v-else class="muted">No sector price data yet today.</p>
  </div>
</template>
