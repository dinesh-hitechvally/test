<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import client from '../api/client'
import PriceChart from '../components/PriceChart.vue'
import IndicatorChart from '../components/IndicatorChart.vue'
import Pagination from '../components/Pagination.vue'
import { formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const route = useRoute()

const stock = ref(null)
const prices = ref([])
const indicators = ref([])
const signals = ref([])
const signalsPage = ref(1)
const signalsTotalPages = ref(1)
const signalsLoading = ref(false)
const signalsFromFilter = ref('')
const signalsToFilter = ref('')
const forecast = ref({ forecasts: [], disclaimer: '' })
const mlPrediction = ref(null)
const mlModel = ref(null)
const dividends = ref([])
const rightShares = ref([])

const {
  sorted: sortedDividends,
  toggleSort: toggleDividendSort,
  sortIndicator: dividendSortIndicator,
} = useSortableTable(dividends, { defaultKey: 'fiscal_year', defaultDir: 'desc' })

const {
  sorted: sortedRightShares,
  toggleSort: toggleRightShareSort,
  sortIndicator: rightShareSortIndicator,
} = useSortableTable(rightShares, { defaultKey: 'opening_date', defaultDir: 'desc' })
const loading = ref(true)
const fetchingHistory = ref(false)
const fetchHistoryResult = ref('')
const fetchHistoryError = ref('')
const fetchingCorporateActions = ref(false)
const corporateActionsResult = ref('')
const corporateActionsError = ref('')

async function loadAll(symbol) {
  loading.value = true
  signalsPage.value = 1
  const [stockRes, pricesRes, indicatorsRes, forecastRes, mlRes, dividendsRes, rightSharesRes] = await Promise.all([
    client.get(`/stocks/${symbol}`),
    client.get(`/stocks/${symbol}/prices`, { params: { days: 180 } }),
    client.get(`/stocks/${symbol}/indicators`, { params: { days: 180 } }),
    client.get(`/stocks/${symbol}/forecast`),
    client.get(`/stocks/${symbol}/ml-prediction`),
    client.get(`/stocks/${symbol}/dividends`),
    client.get(`/stocks/${symbol}/right-shares`),
  ])

  stock.value = stockRes.data
  prices.value = pricesRes.data
  indicators.value = indicatorsRes.data
  forecast.value = forecastRes.data.forecasts ? forecastRes.data : { forecasts: [], disclaimer: '' }
  mlPrediction.value = mlRes.data.prediction
  mlModel.value = mlRes.data.model
  dividends.value = dividendsRes.data
  rightShares.value = rightSharesRes.data
  loading.value = false

  await loadSignalsPage(1)
}

async function loadSignalsPage(page) {
  signalsLoading.value = true
  try {
    const { data } = await client.get(`/stocks/${route.params.symbol}/signals`, {
      params: {
        page,
        per_page: 30,
        from: signalsFromFilter.value || undefined,
        to: signalsToFilter.value || undefined,
      },
    })
    signals.value = data.data.reverse()
    signalsPage.value = data.page
    signalsTotalPages.value = data.total_pages
  } finally {
    signalsLoading.value = false
  }
}

function applySignalsFilter() {
  loadSignalsPage(1)
}

function clearSignalsFilter() {
  signalsFromFilter.value = ''
  signalsToFilter.value = ''
  loadSignalsPage(1)
}

async function handleFetchCorporateActions() {
  fetchingCorporateActions.value = true
  corporateActionsResult.value = ''
  corporateActionsError.value = ''
  try {
    const { data } = await client.post(`/stocks/${route.params.symbol}/fetch-corporate-actions`)
    const sourceLabel = data.sources?.join(' + ') || 'source'
    corporateActionsResult.value = `${data.dividends} dividend row(s), ${data.right_shares} right-share row(s) refreshed (via ${sourceLabel}).`
    const [dividendsRes, rightSharesRes] = await Promise.all([
      client.get(`/stocks/${route.params.symbol}/dividends`),
      client.get(`/stocks/${route.params.symbol}/right-shares`),
    ])
    dividends.value = dividendsRes.data
    rightShares.value = rightSharesRes.data
  } catch (e) {
    corporateActionsError.value = e.response?.data?.message || 'Fetch failed.'
  } finally {
    fetchingCorporateActions.value = false
  }
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
      <div class="card-head">
        <h3 style="margin: 0">Dividend &amp; Bonus History</h3>
        <button class="btn-secondary btn" :disabled="fetchingCorporateActions" @click="handleFetchCorporateActions">
          {{ fetchingCorporateActions ? 'Fetching…' : 'Refresh Dividend/Bonus Data' }}
        </button>
      </div>
      <p v-if="corporateActionsResult" class="muted">{{ corporateActionsResult }}</p>
      <p v-if="corporateActionsError" class="error-text">{{ corporateActionsError }}</p>

      <table class="table" v-if="dividends.length">
        <thead>
          <tr>
            <th class="sortable" @click="toggleDividendSort('fiscal_year')">Fiscal Year {{ dividendSortIndicator('fiscal_year') }}</th>
            <th class="sortable" @click="toggleDividendSort('bonus_share_pct')">Bonus Share {{ dividendSortIndicator('bonus_share_pct') }}</th>
            <th class="sortable" @click="toggleDividendSort('cash_dividend_pct')">Cash Dividend {{ dividendSortIndicator('cash_dividend_pct') }}</th>
            <th class="sortable" @click="toggleDividendSort('total_dividend_pct')">Total Dividend {{ dividendSortIndicator('total_dividend_pct') }}</th>
            <th class="sortable" @click="toggleDividendSort('book_closure_date')">Book Closure {{ dividendSortIndicator('book_closure_date') }}</th>
            <th class="sortable" @click="toggleDividendSort('bonus_listing_date')">Bonus Listing {{ dividendSortIndicator('bonus_listing_date') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="d in sortedDividends" :key="d.id">
            <td>{{ d.fiscal_year }}</td>
            <td>{{ d.bonus_share_pct !== null ? `${d.bonus_share_pct}%` : '—' }}</td>
            <td>{{ d.cash_dividend_pct !== null ? `${d.cash_dividend_pct}%` : '—' }}</td>
            <td><strong>{{ d.total_dividend_pct !== null ? `${d.total_dividend_pct}%` : '—' }}</strong></td>
            <td class="muted">{{ d.book_closure_date || '—' }}</td>
            <td class="muted">{{ d.bonus_listing_date || '—' }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">
        No dividend/bonus history recorded yet for {{ stock.symbol }} — click "Refresh Dividend/Bonus Data" to fetch it.
        (Pulled from ShareSansar first, falling back to the official nepalstock.com feed if that comes back empty —
        cash dividend and bonus share % are covered either way; full right-share detail needs ShareSansar.)
      </p>

      <template v-if="rightShares.length">
        <h4>Right Share History</h4>
        <table class="table">
          <thead>
            <tr>
              <th class="sortable" @click="toggleRightShareSort('ratio')">Ratio {{ rightShareSortIndicator('ratio') }}</th>
              <th class="sortable" @click="toggleRightShareSort('total_units')">Units {{ rightShareSortIndicator('total_units') }}</th>
              <th class="sortable" @click="toggleRightShareSort('issue_price')">Issue Price {{ rightShareSortIndicator('issue_price') }}</th>
              <th class="sortable" @click="toggleRightShareSort('opening_date')">Opening {{ rightShareSortIndicator('opening_date') }}</th>
              <th class="sortable" @click="toggleRightShareSort('closing_date')">Closing {{ rightShareSortIndicator('closing_date') }}</th>
              <th class="sortable" @click="toggleRightShareSort('status')">Status {{ rightShareSortIndicator('status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in sortedRightShares" :key="r.id">
              <td>{{ r.ratio || '—' }}</td>
              <td>{{ r.total_units !== null ? Number(r.total_units).toLocaleString() : '—' }}</td>
              <td>{{ r.issue_price !== null ? `Rs. ${formatPrice(r.issue_price)}` : '—' }}</td>
              <td class="muted">{{ r.opening_date || '—' }}</td>
              <td class="muted">{{ r.closing_date || '—' }}</td>
              <td class="muted">{{ r.status || '—' }}</td>
            </tr>
          </tbody>
        </table>
      </template>
    </div>

    <div class="card" style="margin-top: 16px">
      <h3>Signal History</h3>
      <p class="muted" style="margin-top: -6px">
        "Predicted" is what an earlier trend forecast projected for that date, so you can compare it against what the
        price actually did.
      </p>

      <div class="signal-filters">
        <label class="filter-field">
          <span class="muted small">From</span>
          <input v-model="signalsFromFilter" type="date" class="input" />
        </label>
        <label class="filter-field">
          <span class="muted small">To</span>
          <input v-model="signalsToFilter" type="date" class="input" />
        </label>
        <button class="btn-secondary btn" :disabled="signalsLoading" @click="applySignalsFilter">Apply</button>
        <button
          v-if="signalsFromFilter || signalsToFilter"
          class="btn-secondary btn"
          :disabled="signalsLoading"
          @click="clearSignalsFilter"
        >
          Clear
        </button>
      </div>

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
      <p v-if="!signalsLoading && signals.length === 0" class="muted">No signals yet.</p>

      <Pagination :model-value="signalsPage" :total-pages="signalsTotalPages" :disabled="signalsLoading" @update:model-value="loadSignalsPage" />
    </div>
  </div>
</template>

<style scoped>
.signal-filters {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  margin-bottom: 14px;
  flex-wrap: wrap;
}

.filter-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.small {
  font-size: 0.78rem;
}

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

.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
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
