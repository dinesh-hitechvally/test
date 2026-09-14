<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import client from '../api/client'
import { useStocksStore } from '../stores/stocks'
import { formatPrice } from '../utils/format'
import StatCard from '../components/StatCard.vue'
import SearchableSelect from '../components/SearchableSelect.vue'

const route = useRoute()
const router = useRouter()
const stocksStore = useStocksStore()

const stockOptions = computed(() => stocksStore.stocks.map((s) => ({ value: s.symbol, label: `${s.symbol} — ${s.company_name}` })))

const selected = ref(route.params.symbol || '')
const data = ref(null)
const loading = ref(false)
const error = ref('')

function fmt(v) {
  return v === null || v === undefined ? '—' : formatPrice(v)
}

function toneOf(word) {
  if (['bullish', 'buy', 'strong_buy', 'up'].includes(word)) return 'positive'
  if (['bearish', 'sell', 'strong_sell', 'down'].includes(word)) return 'negative'
  return ''
}

async function load() {
  if (!selected.value) {
    data.value = null
    return
  }

  loading.value = true
  error.value = ''
  try {
    const { data: res } = await client.get(`/reports/analyst/${selected.value}`)
    data.value = res
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load analyst report.'
    data.value = null
  } finally {
    loading.value = false
  }
}

function onSelect() {
  router.replace({ params: { symbol: selected.value || undefined } })
}

watch(
  () => route.params.symbol,
  (symbol) => {
    selected.value = symbol || ''
    load()
  }
)

onMounted(async () => {
  if (stocksStore.stocks.length === 0) await stocksStore.fetchStocks()
  if (selected.value) await load()
})

// Forecast direction from the model's own last vs. current predicted close —
// the only one of the four lenses below that isn't already a labeled field.
const forecastDirection = computed(() => {
  const f = data.value?.forecast?.forecasts
  const close = data.value?.latest_price?.close_price
  if (!f?.length || close == null) return null
  const last = Number(f[f.length - 1].predicted_close)
  const cur = Number(close)
  if (last > cur * 1.005) return 'up'
  if (last < cur * 0.995) return 'down'
  return 'flat'
})

// Tallies the app's 4 independent lenses on direction — technical read,
// rule-based signal, ML model, statistical forecast — so a reader sees at a
// glance whether the evidence agrees or conflicts, rather than having to
// mentally cross-reference 4 separate cards themselves.
const consensus = computed(() => {
  if (!data.value) return null
  const votes = []

  const bias = data.value.technical?.probability_assessment?.overall_bias
  if (bias) votes.push({ label: 'Technical read', lean: bias === 'bullish' ? 'up' : bias === 'bearish' ? 'down' : 'neutral' })

  const sig = data.value.signal?.signal
  if (sig) {
    const lean = ['buy', 'strong_buy'].includes(sig) ? 'up' : ['sell', 'strong_sell'].includes(sig) ? 'down' : 'neutral'
    votes.push({ label: 'Signal engine', lean })
  }

  if (data.value.ml_prediction) {
    votes.push({ label: 'ML model', lean: data.value.ml_prediction.direction })
  }

  if (forecastDirection.value) {
    votes.push({ label: 'Statistical forecast', lean: forecastDirection.value === 'flat' ? 'neutral' : forecastDirection.value })
  }

  const up = votes.filter((v) => v.lean === 'up').length
  const down = votes.filter((v) => v.lean === 'down').length

  let verdict
  if (up === 0 && down === 0) verdict = 'neutral'
  else if (up > down) verdict = 'leaning bullish'
  else if (down > up) verdict = 'leaning bearish'
  else verdict = 'mixed/conflicting'

  return { votes, up, down, total: votes.length, verdict }
})

const summary = computed(() => {
  if (!data.value) return []
  const d = data.value
  const lines = []

  lines.push(
    `${d.stock.symbol} (${d.stock.company_name}) trades at Rs. ${fmt(d.latest_price?.close_price)}` +
      (d.change_pct !== null ? `, ${d.change_pct > 0 ? 'up' : 'down'} ${Math.abs(d.change_pct)}% today` : '') +
      (d.returns?.['1m'] !== null && d.returns?.['1m'] !== undefined ? `, and ${d.returns['1m'] > 0 ? '+' : ''}${d.returns['1m']}% over the past month.` : '.')
  )

  if (consensus.value && consensus.value.total > 0) {
    const c = consensus.value
    lines.push(
      `Across the ${c.total} independent lenses this report checks (technical read, rule-based signal, ML model, statistical forecast — whichever are available), the evidence is ${c.verdict}: ${c.up} lean bullish, ${c.down} lean bearish` +
        (c.total - c.up - c.down > 0 ? `, ${c.total - c.up - c.down} neutral.` : '.')
    )
  }

  if (d.technical?.available !== false) {
    const t = d.technical.trend
    if (t.direction !== 'unknown') {
      lines.push(
        `Technically, the trend reads ${t.direction}: price is ${t.price_vs_sma50} its 50-day average, which sits ${t.sma50_vs_sma200} the 200-day average.`
      )
    }
  }

  if (d.dividend) {
    lines.push(
      `${d.stock.symbol} has paid a dividend in ${d.dividend.years_recorded} of its recorded fiscal years, most recently ${d.dividend.latest_total_pct}% for ${d.dividend.latest_fiscal_year}` +
        (d.dividend.dividend_yield_pct !== null ? ` — a trailing yield of ${d.dividend.dividend_yield_pct}% at the current price.` : '.')
    )
  } else {
    lines.push(`No dividend history recorded yet for ${d.stock.symbol} — check its stock detail page to fetch it.`)
  }

  if (d.signal) {
    let line = `The rule-based signal engine currently flags ${d.stock.symbol} as ${d.signal.signal.replace('_', ' ')}`
    if (d.signal.accuracy) {
      const a = d.signal.accuracy
      const beats = a.win_rate > a.baseline_win_rate
      line += `. Historically, this exact signal type has won ${a.win_rate}% of the time over a ${a.horizon_days}-day horizon (n=${a.sample_size.toLocaleString()}), ${beats ? 'beating' : 'trailing'} its ${a.baseline_win_rate}% baseline — ${beats ? 'a real edge' : 'not yet a proven edge'}.`
    } else {
      line += '.'
    }
    lines.push(line)
  }

  if (d.ml_prediction) {
    const m = d.ml_prediction
    lines.push(
      `The ML model predicts ${m.direction} over the next ${m.horizon_days} trading days at ${Math.round(m.probability * 100)}% confidence. Take that with real caution: the model itself is only ${Math.round(m.model_accuracy * 100)}% accurate historically, ${m.beats_baseline ? 'edging out' : 'not beating'} its ${Math.round(m.model_baseline_accuracy * 100)}% naive baseline.`
    )
  }

  return lines
})
</script>

<template>
  <div>
    <h1>Analyst Report</h1>
    <p class="muted">
      Every lens this app has on one stock, synthesized in one place — price performance, technical read, dividend
      history, rule-based signal (with its own honest track record), ML direction call, and statistical forecast.
      Not financial advice — a merge of already-computed data, not a new prediction of its own.
    </p>

    <div class="card">
      <SearchableSelect v-model="selected" :options="stockOptions" style="max-width: 340px" placeholder="Select a stock…" @change="onSelect" />
    </div>

    <p v-if="loading" class="muted" style="margin-top: 16px">Loading…</p>
    <p v-else-if="error" class="muted" style="margin-top: 16px">{{ error }}</p>

    <template v-else-if="data">
      <div class="page-header" style="margin-top: 16px">
        <div>
          <h2 style="margin: 0">{{ data.stock.symbol }} <span class="muted" style="font-weight: 400">{{ data.stock.company_name }}</span></h2>
          <span v-if="data.signal" class="badge" :class="data.signal.signal">{{ data.signal.signal.replace('_', ' ') }}</span>
        </div>
        <div class="header-right">
          <div class="price">Rs. {{ fmt(data.latest_price?.close_price) }}</div>
          <RouterLink :to="{ name: 'stock-detail', params: { symbol: data.stock.symbol } }" class="btn-secondary btn">Full Stock Detail</RouterLink>
        </div>
      </div>

      <div class="card summary-card">
        <h3>Analyst Summary</h3>
        <p v-for="(line, i) in summary" :key="i">{{ line }}</p>
      </div>

      <div class="grid grid-cards" style="margin-top: 16px">
        <StatCard label="Change Today" :value="data.change_pct !== null ? `${data.change_pct > 0 ? '+' : ''}${data.change_pct}%` : '—'" :tone="data.change_pct > 0 ? 'positive' : data.change_pct < 0 ? 'negative' : 'neutral'" />
        <StatCard label="1M Return" :value="data.returns?.['1m'] !== null ? `${data.returns['1m'] > 0 ? '+' : ''}${data.returns['1m']}%` : '—'" :tone="data.returns?.['1m'] > 0 ? 'positive' : 'negative'" />
        <StatCard label="Dividend Yield" :value="data.dividend?.dividend_yield_pct !== null && data.dividend?.dividend_yield_pct !== undefined ? `${data.dividend.dividend_yield_pct}%` : '—'" />
        <StatCard label="ML Direction" :value="data.ml_prediction ? data.ml_prediction.direction : '—'" :tone="data.ml_prediction ? toneOf(data.ml_prediction.direction) : 'neutral'" />
      </div>

      <div v-if="consensus && consensus.total > 0" class="card" style="margin-top: 16px">
        <h3>Consensus Across Lenses</h3>
        <table class="table">
          <thead><tr><th>Lens</th><th>Lean</th></tr></thead>
          <tbody>
            <tr v-for="v in consensus.votes" :key="v.label">
              <td>{{ v.label }}</td>
              <td :class="toneOf(v.lean)">{{ v.lean }}</td>
            </tr>
          </tbody>
        </table>
        <p class="muted" style="margin-top: 8px">
          Overall: <strong>{{ consensus.verdict }}</strong> ({{ consensus.up }} bullish / {{ consensus.down }} bearish
          out of {{ consensus.total }}).
        </p>
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Technical Snapshot</h3>
          <template v-if="data.technical.available !== false">
            <p><strong>Trend:</strong> {{ data.technical.trend.direction }} (price {{ data.technical.trend.price_vs_sma50 }} SMA50, which is {{ data.technical.trend.sma50_vs_sma200 }} SMA200)</p>
            <p v-if="data.technical.rsi.value !== null"><strong>RSI:</strong> {{ data.technical.rsi.value }} ({{ data.technical.rsi.state }})</p>
            <p v-if="data.technical.macd.position"><strong>MACD:</strong> {{ data.technical.macd.position.replace('_', ' ') }}</p>
            <RouterLink :to="{ name: 'reports-technical', params: { symbol: data.stock.symbol } }">Full Technical Analysis →</RouterLink>
          </template>
          <p v-else class="muted">Not enough price history yet for a technical read.</p>
        </div>

        <div class="card">
          <h3>Dividend History</h3>
          <template v-if="data.dividend">
            <table class="table">
              <thead><tr><th>FY</th><th>Cash</th><th>Bonus</th><th>Total</th></tr></thead>
              <tbody>
                <tr v-for="h in data.dividend.history" :key="h.id">
                  <td>{{ h.fiscal_year }}</td>
                  <td>{{ h.cash_dividend_pct !== null ? `${h.cash_dividend_pct}%` : '—' }}</td>
                  <td>{{ h.bonus_share_pct !== null ? `${h.bonus_share_pct}%` : '—' }}</td>
                  <td><strong>{{ h.total_dividend_pct !== null ? `${h.total_dividend_pct}%` : '—' }}</strong></td>
                </tr>
              </tbody>
            </table>
            <p v-if="data.dividend.latest_right_share_pct !== null" class="muted" style="margin-top: 8px">
              Latest right share: <strong>{{ data.dividend.latest_right_share_pct }}%</strong>
              ({{ data.dividend.latest_right_share_ratio }}<template v-if="data.dividend.latest_right_share_year"> · {{ data.dividend.latest_right_share_year }}</template>)
              — {{ data.dividend.right_share_count }} issue(s) recorded.
            </p>
            <RouterLink :to="{ name: 'reports-dividends' }">Full Dividend Report →</RouterLink>
          </template>
          <p v-else class="muted">No dividend history recorded yet — visit the stock's detail page to fetch it.</p>
        </div>
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Signal & Track Record</h3>
          <template v-if="data.signal">
            <p><span class="badge" :class="data.signal.signal">{{ data.signal.signal.replace('_', ' ') }}</span> as of {{ data.signal.trade_date?.slice(0, 10) }}</p>
            <ul>
              <li v-for="(r, i) in data.signal.reasons" :key="i" class="muted">{{ r }}</li>
            </ul>
            <p v-if="data.signal.accuracy" class="muted">
              This signal type has historically won {{ data.signal.accuracy.win_rate }}% of the time
              (baseline {{ data.signal.accuracy.baseline_win_rate }}%, n={{ data.signal.accuracy.sample_size.toLocaleString() }}).
            </p>
          </template>
          <p v-else class="muted">No signal computed yet.</p>
        </div>

        <div class="card">
          <h3>ML Direction Prediction</h3>
          <template v-if="data.ml_prediction">
            <p>
              <span class="badge" :class="toneOf(data.ml_prediction.direction)">{{ data.ml_prediction.direction }}</span>
              {{ Math.round(data.ml_prediction.probability * 100) }}% confidence, ~{{ data.ml_prediction.horizon_days }} trading days out
            </p>
            <p class="muted">
              Model accuracy: {{ Math.round(data.ml_prediction.model_accuracy * 100) }}% vs.
              {{ Math.round(data.ml_prediction.model_baseline_accuracy * 100) }}% baseline —
              {{ data.ml_prediction.beats_baseline ? 'beats' : "doesn't beat" }} a naive guess.
            </p>
          </template>
          <p v-else class="muted">Not enough price history for this stock yet, or the model hasn't been trained.</p>
        </div>
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>Statistical Forecast</h3>
        <template v-if="data.forecast.forecasts.length">
          <table class="table">
            <thead><tr><th>Target Date</th><th>Predicted Close</th></tr></thead>
            <tbody>
              <tr v-for="f in data.forecast.forecasts" :key="f.target_date">
                <td>{{ f.target_date }}</td>
                <td>Rs. {{ fmt(f.predicted_close) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="data.forecast.accuracy" class="muted" style="margin-top: 8px">
            MAPE {{ (data.forecast.accuracy.mape * 100).toFixed(1) }}%, directional accuracy
            {{ Math.round(data.forecast.accuracy.directional_accuracy * 100) }}% —
            {{ data.forecast.accuracy.beats_coin_flip ? 'beats' : "doesn't beat" }} a coin flip.
          </p>
        </template>
        <p v-else class="muted">No forecast generated yet.</p>
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>Returns</h3>
        <table class="table">
          <tbody>
            <tr>
              <td v-for="(key, label) in { '1 Week': '1w', '1 Month': '1m', '3 Month': '3m', '6 Month': '6m', '1 Year': '1y', YTD: 'ytd' }" :key="key">
                <p class="muted small" style="margin: 0">{{ label }}</p>
                <p :class="data.returns?.[key] > 0 ? 'positive' : data.returns?.[key] < 0 ? 'negative' : ''" style="margin: 2px 0 0; font-weight: 700">
                  {{ data.returns?.[key] !== null && data.returns?.[key] !== undefined ? `${data.returns[key] > 0 ? '+' : ''}${data.returns[key]}%` : '—' }}
                </p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <p v-else class="muted" style="margin-top: 16px">Select a stock above to see its analyst report.</p>
  </div>
</template>

<style scoped>
.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}

.summary-card {
  margin-top: 16px;
  background: #f8faff;
  border-color: #dbeafe;
}

.summary-card p {
  line-height: 1.6;
  font-size: 0.92rem;
  margin: 0 0 10px;
}

.summary-card p:last-child {
  margin-bottom: 0;
}

.small {
  font-size: 0.75rem;
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
</style>
