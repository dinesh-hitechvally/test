<script setup>
import { computed } from 'vue'
import { Bar } from 'vue-chartjs'
import { Chart as ChartJS, BarElement, CategoryScale, LinearScale, Tooltip } from 'chart.js'

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip)

const props = defineProps({
  sectors: { type: Array, required: true }, // [{ sector, count, avg_score }]
})

const chartData = computed(() => {
  const top = [...props.sectors].sort((a, b) => b.count - a.count).slice(0, 8)

  return {
    labels: top.map((s) => s.sector),
    datasets: [
      {
        data: top.map((s) => s.count),
        backgroundColor: '#2563eb',
        borderRadius: 4,
      },
    ],
  }
})

const options = {
  indexAxis: 'y',
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
}
</script>

<template>
  <div style="height: 220px">
    <Bar :data="chartData" :options="options" />
  </div>
</template>
