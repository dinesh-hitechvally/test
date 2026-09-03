<script setup>
import { computed } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js'

ChartJS.register(ArcElement, Tooltip, Legend)

const props = defineProps({
  breadth: { type: Object, required: true }, // { advancing, declining, unchanged }
})

const chartData = computed(() => ({
  labels: ['Advancing', 'Declining', 'Unchanged'],
  datasets: [
    {
      data: [props.breadth.advancing, props.breadth.declining, props.breadth.unchanged],
      backgroundColor: ['#15803d', '#b91c1c', '#94a3b8'],
      borderWidth: 0,
    },
  ],
}))

const options = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
  },
}

const hasData = computed(() => props.breadth.advancing + props.breadth.declining + props.breadth.unchanged > 0)
</script>

<template>
  <div style="height: 220px">
    <Doughnut v-if="hasData" :data="chartData" :options="options" />
    <p v-else class="muted" style="padding-top: 80px; text-align: center">Not enough data yet.</p>
  </div>
</template>
