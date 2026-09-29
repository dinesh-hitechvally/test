<script setup>
import { computed } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js'

ChartJS.register(ArcElement, Tooltip, Legend)

const COLORS = ['#2563eb', '#dc2626', '#15803d', '#d97706', '#7c3aed', '#0891b2', '#be185d', '#4d7c0f', '#0e7490', '#9333ea']

const props = defineProps({
  slices: { type: Array, required: true }, // [{ label, value }]
})

const chartData = computed(() => ({
  labels: props.slices.map((s) => s.label),
  datasets: [
    {
      data: props.slices.map((s) => s.value),
      backgroundColor: props.slices.map((_, i) => COLORS[i % COLORS.length]),
      borderWidth: 0,
    },
  ],
}))

const options = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
    tooltip: {
      callbacks: {
        label: (ctx) => {
          const total = ctx.dataset.data.reduce((a, b) => a + b, 0)
          const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : '0'
          return `${ctx.label}: Rs. ${ctx.parsed.toLocaleString()} (${pct}%)`
        },
      },
    },
  },
}

const hasData = computed(() => props.slices.length > 0)
</script>

<template>
  <div style="height: 300px">
    <Doughnut v-if="hasData" :data="chartData" :options="options" />
    <p v-else class="muted" style="padding-top: 120px; text-align: center">No holdings to break down yet.</p>
  </div>
</template>
