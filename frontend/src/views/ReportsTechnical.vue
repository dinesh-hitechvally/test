<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import client from '../api/client'
import { useStocksStore } from '../stores/stocks'
import { formatPrice } from '../utils/format'
import StatCard from '../components/StatCard.vue'

const route = useRoute()
const router = useRouter()
const stocksStore = useStocksStore()

const selected = ref(route.params.symbol || '')
const data = ref(null)
const loading = ref(false)
const error = ref('')

const BULLISH_WORDS = ['bullish', 'breakout_up', 'accumulation', 'above', 'above_signal', 'rising', 'oversold']
const BEARISH_WORDS = ['bearish', 'breakdown_down', 'distribution', 'below', 'below_signal', 'falling', 'overbought']

function biasClass(word) {
  if (word === null || word === undefined) return 'hold'
  if (BULLISH_WORDS.includes(word)) return 'buy'
  if (BEARISH_WORDS.includes(word)) return 'sell'
  return 'hold'
}

function fmt(v) {
  return v === null || v === undefined ? '—' : formatPrice(v)
}

const report = computed(() => data.value?.report ?? null)

async function load() {
  if (!selected.value) {
    data.value = null
    return
  }

  loading.value = true
  error.value = ''
  try {
    const { data: res } = await client.get(`/reports/technical/${selected.value}`)
    data.value = res
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load technical analysis.'
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
</script>

<template>
  <div>
    <h1>Technical Analysis</h1>
    <p class="muted">
      A full rule-based technical read on one stock — trend, levels, candlesticks, volume, momentum, Fibonacci, and a
      suggested target/stop/risk-reward setup. Every section is a transparent heuristic on already-computed
      indicators, not a fitted model — not financial advice.
    </p>

    <div class="card">
      <select v-model="selected" class="input" style="max-width: 340px" @change="onSelect">
        <option value="" disabled>Select a stock…</option>
        <option v-for="s in stocksStore.stocks" :key="s.id" :value="s.symbol">{{ s.symbol }} — {{ s.company_name }}</option>
      </select>
    </div>

    <p v-if="loading" class="muted" style="margin-top: 16px">Loading…</p>
    <p v-else-if="error" class="muted" style="margin-top: 16px">{{ error }}</p>

    <template v-else-if="report && report.available === false">
      <p class="muted" style="margin-top: 16px">{{ report.message }}</p>
    </template>

    <template v-else-if="report">
      <div class="card-head" style="margin-top: 16px">
        <h2 style="margin: 0">{{ data.stock.symbol }} — {{ data.stock.company_name }}</h2>
        <RouterLink :to="{ name: 'stock-detail', params: { symbol: data.stock.symbol } }" class="btn-secondary btn">
          Full Stock Detail
        </RouterLink>
      </div>
      <p class="muted">Rs. {{ fmt(report.close) }} as of {{ report.as_of }}</p>

      <!-- Probability assessment -->
      <div class="grid grid-cards" style="margin-top: 8px">
        <StatCard label="Bullish" :value="`${report.probability_assessment.bullish_pct}%`" tone="positive" />
        <StatCard label="Neutral" :value="`${report.probability_assessment.neutral_pct}%`" />
        <StatCard label="Bearish" :value="`${report.probability_assessment.bearish_pct}%`" tone="negative" />
        <StatCard label="Overall Bias" :value="report.probability_assessment.overall_bias" :tone="report.probability_assessment.overall_bias === 'bullish' ? 'positive' : report.probability_assessment.overall_bias === 'bearish' ? 'negative' : 'neutral'" />
      </div>
      <p class="muted small">{{ report.probability_assessment.disclaimer }} ({{ report.probability_assessment.signals_considered }} signals tallied.)</p>

      <!-- Trend + Trade Setup -->
      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Trend</h3>
          <p><span class="badge" :class="biasClass(report.trend.direction)">{{ report.trend.direction }}</span></p>
          <table class="kv" v-if="report.trend.direction !== 'unknown'">
            <tbody>
              <tr><td>Price vs SMA50</td><td>{{ report.trend.price_vs_sma50 }}</td></tr>
              <tr><td>SMA50 vs SMA200</td><td>{{ report.trend.sma50_vs_sma200 }}</td></tr>
              <tr><td>SMA50 slope</td><td>{{ report.trend.sma50_slope }}</td></tr>
            </tbody>
          </table>
          <p v-else class="muted">{{ report.trend.reason }}</p>
        </div>

        <div class="card">
          <h3>Trade Setup</h3>
          <p>
            <span class="badge" :class="biasClass(report.trade_setup.bias)">{{ report.trade_setup.bias }}</span>
            <span v-if="report.trade_setup.attractive !== null" class="muted" style="margin-left: 8px">
              {{ report.trade_setup.attractive ? 'Favorable risk/reward' : 'Weak risk/reward' }}
            </span>
          </p>
          <table class="kv">
            <tbody>
              <tr><td>Entry reference</td><td>Rs. {{ fmt(report.trade_setup.entry_reference) }}</td></tr>
              <tr><td>Price target</td><td>Rs. {{ fmt(report.trade_setup.target) }}</td></tr>
              <tr><td>Stop-loss</td><td>Rs. {{ fmt(report.trade_setup.stop_loss) }}</td></tr>
              <tr><td>Risk / share</td><td>Rs. {{ fmt(report.trade_setup.risk_per_share) }}</td></tr>
              <tr><td>Reward / share</td><td>Rs. {{ fmt(report.trade_setup.reward_per_share) }}</td></tr>
              <tr><td>Risk : Reward</td><td>{{ report.trade_setup.risk_reward_ratio !== null ? `1 : ${report.trade_setup.risk_reward_ratio}` : '—' }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Moving averages + RSI/MACD/BB -->
      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Moving Averages <span class="badge" :class="biasClass(report.moving_averages.alignment)">{{ report.moving_averages.alignment }}</span></h3>
          <table class="table">
            <thead><tr><th>Period</th><th>Value</th><th>Position</th></tr></thead>
            <tbody>
              <tr v-for="ma in report.moving_averages.series" :key="ma.period">
                <td>{{ ma.period }}-day</td>
                <td>{{ ma.value !== null ? `Rs. ${fmt(ma.value)}` : '—' }}</td>
                <td>{{ ma.position || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="card">
          <h3>Momentum &amp; Volatility</h3>
          <table class="kv">
            <tbody>
              <tr><td>RSI (14)</td><td>{{ report.rsi.value ?? '—' }} <span class="badge" :class="biasClass(report.rsi.state)">{{ report.rsi.state }}</span></td></tr>
              <tr v-if="report.rsi.divergence"><td>RSI divergence</td><td><span class="badge" :class="biasClass(report.rsi.divergence)">{{ report.rsi.divergence }}</span></td></tr>
              <tr><td>MACD</td><td>{{ report.macd.macd ?? '—' }} vs signal {{ report.macd.signal ?? '—' }} <span class="badge" :class="biasClass(report.macd.position)">{{ (report.macd.position || '').replace('_', ' ') }}</span></td></tr>
              <tr v-if="report.macd.crossover"><td>MACD crossover</td><td><span class="badge" :class="biasClass(report.macd.crossover)">{{ report.macd.crossover.replace('_', ' ') }}</span></td></tr>
              <tr><td>MACD momentum</td><td>{{ report.macd.momentum || '—' }}</td></tr>
              <tr><td>Bollinger Bands</td><td>Rs. {{ fmt(report.bollinger_bands.lower) }} – {{ fmt(report.bollinger_bands.upper) }} <span class="badge" :class="biasClass(report.bollinger_bands.position)">{{ (report.bollinger_bands.position || '').replaceAll('_', ' ') }}</span></td></tr>
              <tr><td>Band width</td><td>{{ report.bollinger_bands.bandwidth_pct ?? '—' }}% <span v-if="report.bollinger_bands.squeeze" class="muted">(squeeze)</span></td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Support/Resistance + Volume/Breakout -->
      <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
        <div class="card">
          <h3>Support &amp; Resistance</h3>
          <table class="table">
            <thead><tr><th>Type</th><th>Level</th><th>Strength</th></tr></thead>
            <tbody>
              <tr v-for="(r, idx) in report.support_resistance.resistance" :key="`r${idx}`">
                <td><span class="badge sell">Resistance</span></td>
                <td>Rs. {{ fmt(r.price) }}</td>
                <td>{{ r.strength }} touch{{ r.strength === 1 ? '' : 'es' }}</td>
              </tr>
              <tr v-for="(s, idx) in report.support_resistance.support" :key="`s${idx}`">
                <td><span class="badge buy">Support</span></td>
                <td>Rs. {{ fmt(s.price) }}</td>
                <td>{{ s.strength }} touch{{ s.strength === 1 ? '' : 'es' }}</td>
              </tr>
              <tr v-if="report.support_resistance.resistance.length === 0 && report.support_resistance.support.length === 0">
                <td colspan="3" class="muted" style="text-align: center; padding: 16px">No clear levels found yet.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="card">
          <h3>Volume &amp; Breakout</h3>
          <table class="kv">
            <tbody>
              <tr><td>Today's volume</td><td>{{ report.volume_analysis.today_volume?.toLocaleString() }}</td></tr>
              <tr><td>20-day avg volume</td><td>{{ report.volume_analysis.avg_volume_20d?.toLocaleString() }}</td></tr>
              <tr><td>Volume level</td><td>{{ report.volume_analysis.volume_level }}</td></tr>
              <tr><td>OBV trend</td><td>{{ report.volume_analysis.obv_trend }} <span class="badge" :class="biasClass(report.volume_analysis.classification)">{{ report.volume_analysis.classification }}</span></td></tr>
              <tr><td>20-day range</td><td>Rs. {{ fmt(report.breakout.period_low_20d) }} – {{ fmt(report.breakout.period_high_20d) }}</td></tr>
              <tr><td>Breakout state</td><td><span class="badge" :class="biasClass(report.breakout.state)">{{ (report.breakout.state || '').replace('_', ' ') }}</span></td></tr>
            </tbody>
          </table>
          <p class="muted small">{{ report.breakout.note }}</p>
        </div>
      </div>

      <!-- Candlestick patterns -->
      <div class="card" style="margin-top: 16px">
        <h3>Candlestick Patterns (most recent candles)</h3>
        <table class="table" v-if="report.candlestick_patterns.length">
          <thead><tr><th>Pattern</th><th>Signal</th><th>Note</th></tr></thead>
          <tbody>
            <tr v-for="p in report.candlestick_patterns" :key="p.name">
              <td><strong>{{ p.name }}</strong></td>
              <td><span class="badge" :class="biasClass(p.signal)">{{ p.signal }}</span></td>
              <td class="muted">{{ p.note }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">No recognizable candlestick pattern on the most recent candles.</p>
      </div>

      <!-- Fibonacci -->
      <div class="card" style="margin-top: 16px" v-if="report.fibonacci.available">
        <h3>Fibonacci Levels</h3>
        <p class="muted">
          Swing high Rs. {{ fmt(report.fibonacci.swing_high) }} ({{ report.fibonacci.swing_high_date }}) —
          swing low Rs. {{ fmt(report.fibonacci.swing_low) }} ({{ report.fibonacci.swing_low_date }})
        </p>
        <div class="grid" style="grid-template-columns: 1fr 1fr">
          <table class="table">
            <thead><tr><th>Retracement</th><th>Price</th></tr></thead>
            <tbody>
              <tr v-for="lvl in report.fibonacci.retracement_levels" :key="`ret${lvl.ratio}`">
                <td>{{ (lvl.ratio * 100).toFixed(1) }}%</td>
                <td>Rs. {{ fmt(lvl.price) }}</td>
              </tr>
            </tbody>
          </table>
          <table class="table">
            <thead><tr><th>Extension (target)</th><th>Price</th></tr></thead>
            <tbody>
              <tr v-for="lvl in report.fibonacci.extension_levels" :key="`ext${lvl.ratio}`">
                <td>{{ (lvl.ratio * 100).toFixed(1) }}%</td>
                <td>Rs. {{ fmt(lvl.price) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <p v-else class="muted" style="margin-top: 16px">Select a stock above to see its technical analysis.</p>
  </div>
</template>

<style scoped>
.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.kv {
  width: 100%;
  border-collapse: collapse;
}

.kv td {
  padding: 6px 0;
  border-bottom: 1px solid var(--border, #f1f5f9);
  font-size: 0.88rem;
}

.kv td:first-child {
  color: var(--text-muted);
  width: 45%;
}

.small {
  font-size: 0.78rem;
}
</style>
