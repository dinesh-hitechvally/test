<script setup>
import { computed, ref } from 'vue'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Tooltip,
  Legend,
} from 'chart.js'
import zoomPlugin from 'chartjs-plugin-zoom'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend, zoomPlugin)

const props = defineProps({
  prices: { type: Array, default: () => [] },
  indicators: { type: Array, default: () => [] },
  forecasts: { type: Array, default: () => [] },
})

const chartData = computed(() => {
  const priceLabels = props.prices.map((p) => p.trade_date)
  const indicatorByDate = Object.fromEntries(props.indicators.map((i) => [i.trade_date, i]))
  // Forecasts are keyed by the day they were generated FROM, predicting
  // the next trading day's close — shifted forward one position here so
  // each estimate lines up on the same x position as the actual close it
  // was predicting, not the day it was made. daily_prices only contains
  // real trading days, so "next array position" is always "next trading
  // day", no calendar math needed.
  const forecastByGeneratedDate = Object.fromEntries(props.forecasts.map((f) => [f.trade_date, f]))
  const estimatedClose = priceLabels.map((_, idx) => {
    const priorLabel = idx > 0 ? priceLabels[idx - 1] : null
    return priorLabel ? (forecastByGeneratedDate[priorLabel]?.next_close ?? null) : null
  })

  return {
    labels: priceLabels,
    datasets: [
      {
        label: 'Close',
        data: props.prices.map((p) => p.close_price),
        borderColor: '#0f172a',
        backgroundColor: 'transparent',
        pointRadius: 0,
        borderWidth: 2,
      },
      {
        label: 'Estimated Close',
        data: estimatedClose,
        borderColor: '#dc2626',
        backgroundColor: 'transparent',
        pointRadius: 0,
        borderWidth: 1.5,
        borderDash: [5, 4],
      },
      {
        label: 'SMA20',
        data: priceLabels.map((d) => indicatorByDate[d]?.sma_20 ?? null),
        borderColor: '#2563eb',
        backgroundColor: 'transparent',
        pointRadius: 0,
        borderWidth: 1.5,
      },
      {
        label: 'SMA50',
        data: priceLabels.map((d) => indicatorByDate[d]?.sma_50 ?? null),
        borderColor: '#d97706',
        backgroundColor: 'transparent',
        pointRadius: 0,
        borderWidth: 1.5,
      },
      {
        label: 'SMA200',
        data: priceLabels.map((d) => indicatorByDate[d]?.sma_200 ?? null),
        borderColor: '#7c3aed',
        backgroundColor: 'transparent',
        pointRadius: 0,
        borderWidth: 1.5,
      },
    ],
  }
})

const chartRef = ref(null)
const isZoomed = ref(false)

const options = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  scales: { x: { ticks: { maxTicksLimit: 10 } } },
  plugins: {
    zoom: {
      pan: {
        enabled: true,
        mode: 'x',
        modifierKey: null,
        onPanComplete: () => { isZoomed.value = true },
      },
      zoom: {
        wheel: { enabled: true },
        pinch: { enabled: true },
        mode: 'x',
        onZoomComplete: () => { isZoomed.value = true },
      },
      // Zooming in shrinks the visible date range, zooming out expands it
      // back toward the full history — capped there so panning/zooming
      // can't scroll past the data that actually exists.
      limits: {
        x: { min: 'original', max: 'original', minRange: 5 },
      },
    },
  },
}

function resetZoom() {
  chartRef.value?.chart?.resetZoom()
  isZoomed.value = false
}
</script>

<template>
  <div>
    <div class="chart-toolbar">
      <span class="muted small">Scroll or pinch to zoom the date range, drag to move it.</span>
      <button v-if="isZoomed" type="button" class="btn-secondary btn small" @click="resetZoom">Reset Zoom</button>
    </div>
    <div style="height: 320px">
      <Line ref="chartRef" :data="chartData" :options="options" />
    </div>
  </div>
</template>

<style scoped>
.chart-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 6px;
}

.small {
  font-size: 0.78rem;
  padding: 4px 10px;
}
</style>
