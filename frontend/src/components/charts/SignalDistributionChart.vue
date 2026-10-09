<script setup>
import { computed } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js'
import './chartSetup'

ChartJS.register(ArcElement, Tooltip, Legend)

const props = defineProps({
  counts: { type: Object, required: true }, // { buy, hold, sell }
})

const LABELS = {
  buy: 'Buy',
  hold: 'Hold',
  sell: 'Sell',
}

const COLORS = {
  buy: '#15803d',
  hold: '#94a3b8',
  sell: '#b91c1c',
}

const totals = computed(() => ({
  buy: props.counts.buy ?? 0,
  hold: props.counts.hold ?? 0,
  sell: props.counts.sell ?? 0,
}))

const chartData = computed(() => {
  const keys = Object.keys(LABELS).filter((k) => totals.value[k] > 0)

  return {
    labels: keys.map((k) => LABELS[k]),
    datasets: [
      {
        data: keys.map((k) => totals.value[k]),
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

const hasData = computed(() => Object.values(totals.value).some((v) => v > 0))
</script>

<template>
  <div style="height: 220px">
    <Doughnut v-if="hasData" :data="chartData" :options="options" />
    <p v-else class="muted" style="padding-top: 80px; text-align: center">No signals yet.</p>
  </div>
</template>
