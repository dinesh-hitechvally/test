<script setup>
import { computed, onMounted, ref, watch } from 'vue'
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
const forecastHistory = ref([])
const signals = ref([])
const signalsPage = ref(1)
const signalsTotalPages = ref(1)
const signalsLoading = ref(false)
const signalsFromFilter = ref('')
const signalsToFilter = ref('')
const SIGNAL_TYPES = ['strong_buy', 'buy', 'hold', 'sell', 'strong_sell']
const signalsTypeFilter = ref([])
const mlPrediction = ref(null)
const mlModel = ref(null)
const dividends = ref([])
const rightShares = ref([])
const aiOpinion = ref(null)
const aiOpinionMessage = ref('')
const nextCloseAccuracy = ref(null)
const nextCloseForecast = ref(null)
const nextCloseForecastMessage = ref('')

const latestPrice = computed(() => prices.value[prices.value.length - 1] || null)

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
const fetchingCorporateActions = ref(false)
const corporateActionsResult = ref('')
const corporateActionsError = ref('')

async function loadAll(symbol) {
  loading.value = true
  signalsPage.value = 1
  aiOpinion.value = null
  aiOpinionMessage.value = ''
  nextCloseForecast.value = null
  nextCloseForecastMessage.value = ''
  const [stockRes, pricesRes, indicatorsRes, forecastHistoryRes, mlRes, dividendsRes, rightSharesRes, aiRes, forecastRes] = await Promise.all([
    client.get(`/stocks/${symbol}`),
    // Most recent 1000 trading days (~4 years) — the Price & Moving
    // Averages chart zooms/pans within this range. Capped rather than
    // "everything on record" so a stock with a decade of full history
    // doesn't force a multi-thousand-row fetch on every page load.
    client.get(`/stocks/${symbol}/prices`, { params: { days: 1000 } }),
    client.get(`/stocks/${symbol}/indicators`, { params: { days: 1000 } }),
    client.get(`/stocks/${symbol}/forecasts`, { params: { days: 1000 } }),
    client.get(`/stocks/${symbol}/ml-prediction`),
    client.get(`/stocks/${symbol}/dividends`),
    client.get(`/stocks/${symbol}/right-shares`),
    // A plain DB read (the cron pipeline is the only thing that ever calls
    // the AI itself) — safe to fetch on every page load like everything
    // else here, no button/latency/quota concern.
    client.get(`/stocks/${symbol}/ai-opinion`),
    // Same story: forecasts are only ever written by the recalculation
    // pipeline's backfill, never generated on request.
    client.get(`/stocks/${symbol}/next-close-forecast`),
  ])

  stock.value = stockRes.data
  prices.value = pricesRes.data
  indicators.value = indicatorsRes.data
  forecastHistory.value = forecastHistoryRes.data
  mlPrediction.value = mlRes.data.prediction
  mlModel.value = mlRes.data.model
  dividends.value = dividendsRes.data
  rightShares.value = rightSharesRes.data
  if (aiRes.data.available) {
    aiOpinion.value = aiRes.data
  } else {
    aiOpinionMessage.value = aiRes.data.message
  }
  if (forecastRes.data.available) {
    nextCloseForecast.value = forecastRes.data
  } else {
    nextCloseForecastMessage.value = forecastRes.data.message
  }
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
        signal: signalsTypeFilter.value.length ? signalsTypeFilter.value : undefined,
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
  signalsTypeFilter.value = []
  loadSignalsPage(1)
}

function toggleSignalTypeFilter(type) {
  const idx = signalsTypeFilter.value.indexOf(type)
  if (idx === -1) {
    signalsTypeFilter.value.push(type)
  } else {
    signalsTypeFilter.value.splice(idx, 1)
  }
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

onMounted(() => {
  loadAll(route.params.symbol)
  // Global (not per-stock), so fetched once here rather than in loadAll —
  // no need to re-fetch it every time the symbol changes.
  client.get('/reports/next-close-accuracy').then(({ data }) => {
    if (data.available) nextCloseAccuracy.value = data
  })
})
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
      </div>
    </div>

    <div v-if="stock.fundamental" class="fundamentals-row">
      <div class="fundamental-item">
        <span class="muted small">EPS</span>
        <span>{{ formatPrice(stock.fundamental.eps) }}<span v-if="stock.fundamental.eps_fiscal_year" class="muted small"> ({{ stock.fundamental.eps_fiscal_year }})</span></span>
      </div>
      <div class="fundamental-item">
        <span class="muted small">P/E Ratio</span>
        <span>{{ formatPrice(stock.fundamental.pe_ratio) }}</span>
      </div>
      <div class="fundamental-item">
        <span class="muted small">Book Value</span>
        <span>Rs. {{ formatPrice(stock.fundamental.book_value) }}</span>
      </div>
      <div class="fundamental-item">
        <span class="muted small">PBV</span>
        <span>{{ formatPrice(stock.fundamental.pbv) }}</span>
      </div>
      <div class="fundamental-item">
        <span class="muted small">Market Cap</span>
        <span>Rs. {{ (stock.fundamental.market_cap / 1e9).toFixed(2) }}B</span>
      </div>
    </div>

    <p v-if="prices.length < 20" class="muted card">
      Not enough price history yet to compute indicators (need at least 20 trading days) — this fills in once the
      scheduled history fetch reaches this stock, or import a CSV from the Stocks page.
    </p>

    <template v-else>
      <div class="card">
        <h3>Price &amp; Moving Averages</h3>
        <PriceChart :prices="prices" :indicators="indicators" :forecasts="forecastHistory" />
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
          Not enough price history for this stock yet (needs 260+ days) — fills in once the scheduled history fetch
          reaches it.
        </p>
      </template>
    </div>

    <div class="card" style="margin-top: 16px">
      <h3>Estimated Next Close</h3>

      <div v-if="nextCloseAccuracy" class="accuracy-box" :class="{ warn: !nextCloseAccuracy.beats_baseline }">
        <strong>{{ nextCloseAccuracy.beats_baseline ? 'Beats baseline' : "Doesn't beat baseline" }}:</strong>
        measured error {{ nextCloseAccuracy.mape.toFixed(2) }}% vs. simply assuming no price change (baseline
        {{ nextCloseAccuracy.naive_mape.toFixed(2) }}%), and {{ nextCloseAccuracy.direction_accuracy.toFixed(1) }}%
        directional accuracy (a coin flip is 50%) — backtested across {{ nextCloseAccuracy.sample_size.toLocaleString() }}
        real historical day-ahead pairs on {{ nextCloseAccuracy.stocks_used }} stocks.
        <span v-if="!nextCloseAccuracy.beats_baseline">
          In its current form this estimate is not adding value over simply assuming no price change tomorrow — shown
          anyway for transparency, not as a recommendation.
        </span>
      </div>

      <template v-if="nextCloseForecast">
        <div class="next-close-estimate">
          <span class="estimate-price">Rs. {{ formatPrice(nextCloseForecast.next_close) }}</span>
          <span class="muted" v-if="latestPrice">
            vs. today's close of Rs. {{ formatPrice(latestPrice.close_price) }}
            ({{ nextCloseForecast.next_close >= latestPrice.close_price ? '+' : '' }}{{ (((nextCloseForecast.next_close - latestPrice.close_price) / latestPrice.close_price) * 100).toFixed(2) }}%)
          </span>
        </div>
        <ul class="reasons">
          <li v-for="(r, i) in nextCloseForecast.reasons" :key="i">{{ r }}</li>
        </ul>
      </template>
      <p v-else class="muted">{{ nextCloseForecastMessage || 'Not enough price history yet for this stock (needs at least a few weeks of trading).' }}</p>
    </div>

    <div class="card" style="margin-top: 16px">
      <h3>AI Opinion</h3>
      <p class="muted">
        A 4th independent lens, refreshed periodically by a scheduled job — fed the exact same technical/dividend/
        signal/ML data shown above, not new information. Not financial advice.
      </p>

      <div v-if="aiOpinion" class="ai-opinion">
        <span class="badge" :class="aiOpinion.verdict === 'buy' ? 'buy' : aiOpinion.verdict === 'sell' ? 'sell' : 'hold'">
          {{ aiOpinion.verdict }}
        </span>
        <span class="muted">{{ aiOpinion.confidence }} confidence · as of {{ new Date(aiOpinion.generated_at).toLocaleString() }}</span>
        <p style="margin-top: 8px">{{ aiOpinion.reasoning }}</p>
      </div>
      <p v-else class="muted">{{ aiOpinionMessage }}</p>
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
          v-if="signalsFromFilter || signalsToFilter || signalsTypeFilter.length"
          class="btn-secondary btn"
          :disabled="signalsLoading"
          @click="clearSignalsFilter"
        >
          Clear
        </button>
      </div>

      <div class="signal-type-filters">
        <span class="muted small">Signal</span>
        <button
          v-for="type in SIGNAL_TYPES"
          :key="type"
          type="button"
          class="badge signal-type-toggle"
          :class="[type, { active: signalsTypeFilter.includes(type) }]"
          :disabled="signalsLoading"
          @click="toggleSignalTypeFilter(type)"
        >
          {{ formatSignal(type) }}
        </button>
      </div>

      <table class="table">
        <thead>
          <tr><th>Date</th><th>Price</th><th>Forecast Next Close</th><th>Signal</th><th>Score</th><th>Reasons</th></tr>
        </thead>
        <tbody>
          <tr v-for="s in signals" :key="s.id">
            <td>{{ s.trade_date }}</td>
            <td>Rs. {{ formatPrice(s.price_at_signal) }}</td>
            <td>
              <span v-if="s.forecast_price !== null">Rs. {{ formatPrice(s.forecast_price) }}</span>
              <span v-else class="muted">—</span>
            </td>
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

.signal-type-filters {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}

.signal-type-toggle {
  cursor: pointer;
  border: 1px solid transparent;
  opacity: 0.45;
}

.signal-type-toggle.active {
  opacity: 1;
  border-color: currentColor;
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

.fundamentals-row {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  margin-bottom: 16px;
}

.fundamental-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
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

.next-close-estimate {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
}

.estimate-price {
  font-size: 1.3rem;
  font-weight: 700;
}

.reasons {
  margin: 10px 0 0;
  padding-left: 18px;
  font-size: 0.8rem;
  color: var(--text-muted);
}

.ai-opinion {
  margin-top: 12px;
}
</style>
