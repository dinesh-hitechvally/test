<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const TIERS = [
  {
    key: 'short',
    label: 'Short-Term',
    endpoint: '/reports/short-term',
    scoreKey: 'short_term_score',
    signalKey: 'short_term_signal',
    description:
      "Today's fast-moving technical state — SMA20-vs-SMA50 momentum, RSI, MACD, and Bollinger position. Targets a multi-day-to-few-weeks hold. Can flip day to day as indicators move.",
  },
  {
    key: 'mid',
    label: 'Mid-Term',
    endpoint: '/reports/mid-term',
    scoreKey: 'mid_term_score',
    signalKey: 'mid_term_signal',
    description:
      'An established trend read — price vs SMA50/SMA200, RSI in a healthy accumulation band, 6-month return, and volatility. Targets a multi-week-to-few-months hold.',
  },
  {
    key: 'long',
    label: 'Long-Term',
    endpoint: '/reports/long-term',
    scoreKey: 'long_term_score',
    signalKey: null,
    description:
      'A quality/consistency read for buy-and-hold: dividend track record, right-share dilution history, price stability, and 3-year return. This app tracks no EPS, P/E, book value or ROE, so it is NOT fundamental/valuation analysis — only whether a stock has behaved like a steady, shareholder-friendly one so far. There is no long-term "sell" list here for the same reason: this screen only ever ranks candidates to buy and hold.',
  },
]

const activeTier = ref('short')
const activeSide = ref('buy')
const data = ref({ short: [], mid: [], long: [] })
const loading = ref({ short: false, mid: false, long: false })
const loaded = ref({ short: false, mid: false, long: false })

const currentTier = computed(() => TIERS.find((t) => t.key === activeTier.value))

async function loadTier(key) {
  if (loaded.value[key] || loading.value[key]) return
  const tier = TIERS.find((t) => t.key === key)
  loading.value[key] = true
  try {
    const { data: res } = await client.get(tier.endpoint)
    data.value[key] = res.candidates
    loaded.value[key] = true
  } finally {
    loading.value[key] = false
  }
}

watch(activeTier, (key) => loadTier(key), { immediate: true })

function bySide(all, signalKey) {
  if (!signalKey) return all

  return all.filter((r) => {
    const signal = r[signalKey]
    return activeSide.value === 'buy' ? ['buy', 'strong_buy'].includes(signal) : ['sell', 'strong_sell'].includes(signal)
  })
}

const rowsShort = computed(() => bySide(data.value.short, 'short_term_signal'))
const rowsMid = computed(() => bySide(data.value.mid, 'mid_term_signal'))
const rowsLong = computed(() => data.value.long)

const { sorted: sortedShort, toggleSort: toggleShort, sortIndicator: indicatorShort } = useSortableTable(rowsShort, { defaultKey: 'short_term_score', defaultDir: 'desc' })
const { sorted: sortedMid, toggleSort: toggleMid, sortIndicator: indicatorMid } = useSortableTable(rowsMid, { defaultKey: 'mid_term_score', defaultDir: 'desc' })
const { sorted: sortedLong, toggleSort: toggleLong, sortIndicator: indicatorLong } = useSortableTable(rowsLong, { defaultKey: 'long_term_score', defaultDir: 'desc' })

const sorted = computed(() => {
  if (activeTier.value === 'short') return sortedShort.value
  if (activeTier.value === 'mid') return sortedMid.value

  return sortedLong.value
})

function changeTone(pct) {
  if (pct === null || pct === undefined) return ''
  return pct > 0 ? 'positive' : pct < 0 ? 'negative' : ''
}

function selectTier(key) {
  activeTier.value = key
  if (key === 'long') activeSide.value = 'buy'
}
</script>

<template>
  <div>
    <h1>Investment Horizon</h1>
    <p class="muted">Find buy and sell candidates by holding period — short-term (days/weeks), mid-term (weeks/months), and long-term (buy-and-hold).</p>

    <div class="filters">
      <button v-for="t in TIERS" :key="t.key" class="btn-secondary btn" :class="{ active: activeTier === t.key }" @click="selectTier(t.key)">
        {{ t.label }}
      </button>
    </div>

    <p class="muted" style="margin-top: 8px">{{ currentTier.description }}</p>

    <div v-if="currentTier.signalKey" class="filters" style="margin-top: 8px">
      <button class="btn-secondary btn small" :class="{ active: activeSide === 'buy' }" @click="activeSide = 'buy'">Buy Candidates</button>
      <button class="btn-secondary btn small" :class="{ active: activeSide === 'sell' }" @click="activeSide = 'sell'">Sell / Avoid Candidates</button>
    </div>

    <div class="card" style="margin-top: 12px">
      <p v-if="loading[activeTier]" class="muted">Scoring every stock — this can take a moment…</p>
      <template v-else>
        <p class="muted">{{ sorted.length }} candidate{{ sorted.length === 1 ? '' : 's' }}.</p>

        <table v-if="activeTier === 'short'" class="table">
          <thead>
            <tr>
              <th class="sortable" @click="toggleShort('symbol')">Symbol {{ indicatorShort('symbol') }}</th>
              <th class="sortable" @click="toggleShort('sector')">Sector {{ indicatorShort('sector') }}</th>
              <th class="sortable" @click="toggleShort('close')">Price {{ indicatorShort('close') }}</th>
              <th class="sortable" @click="toggleShort('change_pct')">% Chg {{ indicatorShort('change_pct') }}</th>
              <th class="sortable" @click="toggleShort('short_term_score')">Score {{ indicatorShort('short_term_score') }}</th>
              <th class="sortable" @click="toggleShort('short_term_signal')">Signal {{ indicatorShort('short_term_signal') }}</th>
              <th class="sortable" @click="toggleShort('rsi_14')">RSI {{ indicatorShort('rsi_14') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in sorted" :key="s.stock_id" :title="s.reasons.join(' · ')">
              <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
              <td class="muted">{{ s.sector || '—' }}</td>
              <td>{{ formatPrice(s.close) }}</td>
              <td :class="changeTone(s.change_pct)">{{ s.change_pct !== null ? `${s.change_pct > 0 ? '+' : ''}${s.change_pct}%` : '—' }}</td>
              <td><strong>{{ s.short_term_score }}</strong></td>
              <td><span class="badge" :class="s.short_term_signal">{{ s.short_term_signal.replace('_', ' ') }}</span></td>
              <td class="muted">{{ s.rsi_14 !== null ? s.rsi_14.toFixed(1) : '—' }}</td>
            </tr>
            <tr v-if="sorted.length === 0">
              <td colspan="7" class="muted" style="text-align: center; padding: 24px">No candidates right now.</td>
            </tr>
          </tbody>
        </table>

        <table v-else-if="activeTier === 'mid'" class="table">
          <thead>
            <tr>
              <th class="sortable" @click="toggleMid('symbol')">Symbol {{ indicatorMid('symbol') }}</th>
              <th class="sortable" @click="toggleMid('sector')">Sector {{ indicatorMid('sector') }}</th>
              <th class="sortable" @click="toggleMid('close')">Price {{ indicatorMid('close') }}</th>
              <th class="sortable" @click="toggleMid('mid_term_score')">Score {{ indicatorMid('mid_term_score') }}</th>
              <th class="sortable" @click="toggleMid('mid_term_signal')">Signal {{ indicatorMid('mid_term_signal') }}</th>
              <th class="sortable" @click="toggleMid('rsi_14')">RSI {{ indicatorMid('rsi_14') }}</th>
              <th class="sortable" @click="toggleMid('return_6m_pct')">6mo Return {{ indicatorMid('return_6m_pct') }}</th>
              <th class="sortable" @click="toggleMid('volatility_pct')">Volatility {{ indicatorMid('volatility_pct') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in sorted" :key="s.stock_id" :title="s.reasons.join(' · ')">
              <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
              <td class="muted">{{ s.sector || '—' }}</td>
              <td>{{ formatPrice(s.close) }}</td>
              <td><strong>{{ s.mid_term_score }}</strong></td>
              <td><span class="badge" :class="s.mid_term_signal">{{ s.mid_term_signal.replace('_', ' ') }}</span></td>
              <td class="muted">{{ s.rsi_14 !== null ? s.rsi_14.toFixed(1) : '—' }}</td>
              <td :class="changeTone(s.return_6m_pct)">{{ s.return_6m_pct !== null ? `${s.return_6m_pct > 0 ? '+' : ''}${s.return_6m_pct}%` : 'not enough data' }}</td>
              <td class="muted">{{ s.volatility_pct !== null ? `${s.volatility_pct}%` : 'not enough data' }}</td>
            </tr>
            <tr v-if="sorted.length === 0">
              <td colspan="8" class="muted" style="text-align: center; padding: 24px">No candidates right now.</td>
            </tr>
          </tbody>
        </table>

        <table v-else class="table">
          <thead>
            <tr>
              <th class="sortable" @click="toggleLong('symbol')">Symbol {{ indicatorLong('symbol') }}</th>
              <th class="sortable" @click="toggleLong('sector')">Sector {{ indicatorLong('sector') }}</th>
              <th class="sortable" @click="toggleLong('close')">Price {{ indicatorLong('close') }}</th>
              <th class="sortable" @click="toggleLong('long_term_score')">Score {{ indicatorLong('long_term_score') }}</th>
              <th class="sortable" @click="toggleLong('dividend_years_recorded')">Div. Years {{ indicatorLong('dividend_years_recorded') }}</th>
              <th class="sortable" @click="toggleLong('avg_total_dividend_pct')">Avg Div % {{ indicatorLong('avg_total_dividend_pct') }}</th>
              <th class="sortable" @click="toggleLong('right_share_count')">Right Shares {{ indicatorLong('right_share_count') }}</th>
              <th class="sortable" @click="toggleLong('volatility_pct')">Volatility {{ indicatorLong('volatility_pct') }}</th>
              <th class="sortable" @click="toggleLong('return_3y_pct')">3yr Return {{ indicatorLong('return_3y_pct') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in sorted" :key="s.stock_id" :title="s.reasons.join(' · ')">
              <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
              <td class="muted">{{ s.sector || '—' }}</td>
              <td>{{ formatPrice(s.close) }}</td>
              <td><strong>{{ s.long_term_score }}</strong></td>
              <td class="muted">{{ s.dividend_years_recorded }}</td>
              <td class="muted">{{ s.avg_total_dividend_pct !== null ? `${s.avg_total_dividend_pct}%` : '—' }}</td>
              <td class="muted">{{ s.right_share_count }}</td>
              <td class="muted">{{ s.volatility_pct !== null ? `${s.volatility_pct}%` : 'not enough data' }}</td>
              <td :class="changeTone(s.return_3y_pct)">{{ s.return_3y_pct !== null ? `${s.return_3y_pct > 0 ? '+' : ''}${s.return_3y_pct}%` : 'not enough data' }}</td>
            </tr>
            <tr v-if="sorted.length === 0">
              <td colspan="9" class="muted" style="text-align: center; padding: 24px">No candidates right now.</td>
            </tr>
          </tbody>
        </table>
      </template>
    </div>
  </div>
</template>

<style scoped>
.filters {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 4px;
}

.filters .active {
  background: #1e293b;
  color: #fff;
  border-color: #1e293b;
}

.small {
  font-size: 0.78rem;
  padding: 4px 10px;
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}
</style>
