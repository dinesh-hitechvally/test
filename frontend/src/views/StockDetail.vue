<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import client from '../api/client'
import PriceChart from '../components/PriceChart.vue'
import IndicatorChart from '../components/IndicatorChart.vue'
import { formatPrice } from '../utils/format'

const route = useRoute()

const stock = ref(null)
const prices = ref([])
const indicators = ref([])
const signals = ref([])
const forecast = ref({ forecasts: [], disclaimer: '' })
const mlPrediction = ref(null)
const mlModel = ref(null)
const loading = ref(true)
const fetchingHistory = ref(false)
const fetchHistoryResult = ref('')
const fetchHistoryError = ref('')

async function loadAll(symbol) {
  loading.value = true
  const [stockRes, pricesRes, indicatorsRes, signalsRes, forecastRes, mlRes] = await Promise.all([
    client.get(`/stocks/${symbol}`),
    client.get(`/stocks/${symbol}/prices`, { params: { days: 180 } }),
    client.get(`/stocks/${symbol}/indicators`, { params: { days: 180 } }),
    client.get(`/stocks/${symbol}/signals`, { params: { days: 30 } }),
    client.get(`/stocks/${symbol}/forecast`),
    client.get(`/stocks/${symbol}/ml-prediction`),
  ])

  stock.value = stockRes.data
  prices.value = pricesRes.data
  indicators.value = indicatorsRes.data
  signals.value = signalsRes.data.reverse()
  forecast.value = forecastRes.data.forecasts ? forecastRes.data : { forecasts: [], disclaimer: '' }
  mlPrediction.value = mlRes.data.prediction
  mlModel.value = mlRes.data.model
  loading.value = false
}

function formatSignal(label) {
  return label.replace('_', ' ')
}

async function handleFetchFullHistory() {
  fetchingHistory.value = true
  fetchHistoryResult.value = ''
  fetchHistoryError.value = ''
  try {
    const { data } = await client.post(`/stocks/${route.params.symbol}/fetch-full-history`)
    fetchHistoryResult.value = `Imported ${data.rows_imported} rows (${data.oldest_date} to ${data.newest_date}).`
    await loadAll(route.params.symbol)
  } catch (e) {
    fetchHistoryError.value = e.response?.data?.message || 'Fetch failed.'
  } finally {
    fetchingHistory.value = false
  }
}

onMounted(() => loadAll(route.params.symbol))
watch(() => route.params.symbol, (symbol) => loadAll(symbol))
</script>

<template>
  <div v-if="loading" class="muted">Loading…</div>
  <div v-else>
    <div class="page-header">
      <div>
        <h1>{{ stock.symbol }} <span class="muted" style="font-weight: 400">{{ stock.company_name }}</span></h1>
        <p v-if="stock.latest_signal">
          <span class="badge" :class="stock.latest_signal.signal">{{ formatSignal(stock.latest_signal.signal) }}</span>
          <span class="muted" style="margin-left: 10px">as of {{ stock.latest_signal.trade_date }}</span>
        </p>
      </div>
      <div class="header-right">
        <div class="price">Rs. {{ formatPrice(stock.latest_price?.close_price) }}</div>
        <button class="btn-secondary btn" :disabled="fetchingHistory" @click="handleFetchFullHistory">
          {{ fetchingHistory ? 'Fetching full history…' : 'Fetch Full History' }}
        </button>
      </div>
    </div>

    <p v-if="fetchingHistory" class="muted card">
      Pulling {{ stock.symbol }}'s entire price history from its listing date — this fetches one page of ~50 rows at a
      time and can take a minute or more for stocks with many years of trading. Feel free to leave this page open.
    </p>
    <p v-if="fetchHistoryResult" class="muted card">{{ fetchHistoryResult }}</p>
    <p v-if="fetchHistoryError" class="error-text card">{{ fetchHistoryError }}</p>

    <p v-if="!fetchingHistory && prices.length < 20" class="muted card">
      Not enough price history yet to compute indicators (need at least 20 trading days). Click "Fetch Full History"
      above to pull everything back to listing date in one go, import a CSV from the Stocks page, or keep using
      "Scrape Latest Data" daily to build up history.
    </p>

    <template v-else>
      <div class="card">
        <h3>Price &amp; Moving Averages</h3>
        <PriceChart :prices="prices" :indicators="indicators" :forecasts="forecast.forecasts" />
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>RSI (14)</h3>
          <IndicatorChart
            :indicators="indicators"
            :series="[{ field: 'rsi_14', label: 'RSI 14', color: '#2563eb' }]"
            :y-min="0"
            :y-max="100"
          />
        </div>
        <div class="card">
          <h3>MACD</h3>
          <IndicatorChart
            :indicators="indicators"
            :series="[
              { field: 'macd', label: 'MACD', color: '#2563eb' },
              { field: 'macd_signal', label: 'Signal', color: '#dc2626' },
            ]"
          />
        </div>
      </div>

      <div v-if="forecast.forecasts?.length" class="card" style="margin-top: 16px">
        <h3>Statistical Forecast — Holt's Exponential Smoothing</h3>
        <p class="muted">{{ forecast.disclaimer }}</p>

        <div v-if="forecast.accuracy" class="accuracy-box" :class="{ warn: !forecast.accuracy.beats_coin_flip }">
          <strong>{{ forecast.accuracy.beats_coin_flip ? 'Beats a coin flip' : "Doesn't beat a coin flip" }}:</strong>
          backtested directional accuracy {{ (forecast.accuracy.directional_accuracy * 100).toFixed(1) }}% (vs. 50%
          for a random guess), average error (MAPE) {{ (forecast.accuracy.mape * 100).toFixed(1) }}%, measured across
          {{ forecast.accuracy.test_points }} walk-forward test points on {{ forecast.accuracy.stocks_used }} stocks
          (backtested {{ new Date(forecast.accuracy.computed_at).toLocaleDateString() }}, {{ forecast.accuracy.horizon_days }}-day horizon).
          <span v-if="!forecast.accuracy.beats_coin_flip">
            In its current form this isn't reliably calling direction — shown for transparency, not as a recommendation.
          </span>
        </div>

        <p class="note">
          This can disagree with the Signal above — they use opposite logic on purpose. The Signal is
          <strong>mean-reversion</strong>: it flags Buy when the price has fallen enough to look oversold (RSI, Bollinger
          Bands) and Sell when it's risen enough to look overbought, betting on a reversal. The Forecast is pure
          <strong>trend continuation</strong> (Holt's method: a smoothed level + trend, weighted toward recent prices),
          with no concept of overbought/oversold — it just assumes the recent direction keeps going. Seeing "price
          trending down" here alongside a "Buy" signal (or "trending up" alongside "Sell") is expected, not a bug.
        </p>
        <div class="scroll-table">
          <table class="table">
            <thead>
              <tr><th>Target Date</th><th>Predicted Close</th></tr>
            </thead>
            <tbody>
              <tr v-for="f in forecast.forecasts" :key="f.id">
                <td>{{ f.target_date }}</td>
                <td>Rs. {{ formatPrice(f.predicted_close) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <div class="card" style="margin-top: 16px">
      <h3>ML Direction Prediction (Experimental)</h3>

      <p v-if="!mlModel" class="muted">No model has been trained yet.</p>

      <template v-else>
        <div class="accuracy-box" :class="{ warn: !mlModel.beats_baseline }">
          <strong>{{ mlModel.beats_baseline ? 'Beats baseline' : "Doesn't beat baseline" }}:</strong>
          measured accuracy {{ (mlModel.accuracy * 100).toFixed(1) }}% vs. a naive "always guess the more common
          outcome" baseline of {{ (mlModel.baseline_accuracy * 100).toFixed(1) }}%, on {{ mlModel.test_samples }}
          held-out days across {{ mlModel.stocks_used }} stocks (trained {{ new Date(mlModel.trained_at).toLocaleDateString() }},
          {{ mlModel.horizon_days }}-day horizon).
          <span v-if="!mlModel.beats_baseline">
            In its current form this model is not adding value over a simple guess — shown anyway for transparency,
            not as a recommendation.
          </span>
        </div>

        <div v-if="mlPrediction" class="ml-prediction">
          <span class="badge" :class="mlPrediction.direction === 'up' ? 'buy' : 'sell'">
            {{ mlPrediction.direction === 'up' ? 'Predicts Up' : 'Predicts Down' }}
          </span>
          <span class="muted">
            {{ (mlPrediction.probability * 100).toFixed(1) }}% confidence, as of {{ mlPrediction.as_of_date }}
            (~{{ mlModel.horizon_days }} trading days out)
          </span>
        </div>
        <p v-else class="muted">
          Not enough price history for this stock yet (needs 260+ days) — use "Fetch Full History" above.
        </p>
      </template>
    </div>

    <div class="card" style="margin-top: 16px">
      <h3>Signal History</h3>
      <p class="muted" style="margin-top: -6px">
        "Predicted" is what an earlier trend forecast projected for that date, so you can compare it against what the
        price actually did.
      </p>
      <table class="table">
        <thead>
          <tr><th>Date</th><th>Price</th><th>Predicted</th><th>Signal</th><th>Score</th><th>Reasons</th></tr>
        </thead>
        <tbody>
          <tr v-for="s in signals" :key="s.id">
            <td>{{ s.trade_date }}</td>
            <td>Rs. {{ formatPrice(s.price_at_signal) }}</td>
            <td>{{ s.predicted_close ? `Rs. ${formatPrice(s.predicted_close)}` : '—' }}</td>
            <td><span class="badge" :class="s.signal">{{ formatSignal(s.signal) }}</span></td>
            <td>{{ s.score }}</td>
            <td class="muted">{{ s.reasons.join('; ') }}</td>
          </tr>
        </tbody>
      </table>
      <p v-if="signals.length === 0" class="muted">No signals yet.</p>
    </div>
  </div>
</template>

<style scoped>
.price {
  font-size: 1.6rem;
  font-weight: 700;
  text-align: right;
}

.header-right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
}

.accuracy-box {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 0.85rem;
  line-height: 1.5;
  color: var(--text-muted);
  margin-bottom: 14px;
}

.accuracy-box.warn {
  background: #fef2f2;
  border-color: #fecaca;
}

.ml-prediction {
  display: flex;
  align-items: center;
  gap: 12px;
}

.scroll-table {
  max-height: 320px;
  overflow-y: auto;
}

.note {
  background: #f8fafc;
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 0.83rem;
  color: var(--text-muted);
  line-height: 1.5;
  margin: 10px 0 16px;
}
</style>
