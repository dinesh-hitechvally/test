<script setup>
import { computed } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js'

ChartJS.register(ArcElement, Tooltip, Legend)

const props = defineProps({
  counts: { type: Object, required: true }, // { strong_buy, buy, hold, sell, strong_sell }
})

const LABELS = {
  strong_buy: 'Strong Buy',
  buy: 'Buy',
  hold: 'Hold',
  sell: 'Sell',
  strong_sell: 'Strong Sell',
}

const COLORS = {
  strong_buy: '#15803d',
  buy: '#4d9e6c',
  hold: '#94a3b8',
  sell: '#dc7a4d',
  strong_sell: '#b91c1c',
}

const chartData = computed(() => {
  const keys = Object.keys(LABELS).filter((k) => props.counts[k] > 0)

  return {
    labels: keys.map((k) => LABELS[k]),
    datasets: [
      {
        data: keys.map((k) => props.counts[k]),
        backgroundColor: keys.map((k) => COLORS[k]),
        borderWidth: 0,
      },
    ],
  }
})

const options = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
  },
}

const hasData = computed(() => Object.values(props.counts).some((v) => v > 0))
</script>

<template>
  <div style="height: 220px">
    <Doughnut v-if="hasData" :data="chartData" :options="options" />
    <p v-else class="muted" style="padding-top: 80px; text-align: center">No signals yet.</p>
  </div>
</template>
