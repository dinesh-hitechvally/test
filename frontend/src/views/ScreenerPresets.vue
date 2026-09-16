<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const stocks = ref([])
const fiftyTwoWeek = ref([])
const longTerm = ref([])
const loadingLongTerm = ref(false)
const loading = ref(true)
const activePreset = ref('momentum')

const PRESETS = [
  { key: 'momentum', label: 'Momentum', description: 'Bullish signal, RSI 50-70 (trending up, not yet overbought), and up 2%+ today.' },
  { key: 'oversold', label: 'Oversold', description: 'RSI below 30 — a rebound candidate, not a guarantee.' },
  { key: 'overbought', label: 'Overbought', description: 'RSI above 70 — extended, watch for a pullback.' },
  { key: 'near-low', label: 'Near 52-Week Low', description: 'Within 5% of its 52-week low — the closest thing to a real "value" screen without valuation data (P/E, P/B) tracked yet.' },
  {
    key: 'long-term',
    label: 'Long-Term Investment',
    description:
      'A quality/consistency read for buy-and-hold: dividend track record, right-share dilution history, price stability, and 3-year return — weighted into one transparent score. Not currently a Sell/Strong Sell, and has real trailing liquidity. This is NOT fundamental analysis — this app tracks no EPS, P/E, book value or ROE, so it can\'t tell you if a stock is cheap or expensive, only whether it has behaved like a steady, shareholder-friendly one so far.',
  },
]

const rsiByStock = computed(() => new Map(stocks.value.map((s) => [s.id, s.latest_indicator?.rsi_14 !== undefined && s.latest_indicator?.rsi_14 !== null ? Number(s.latest_indicator.rsi_14) : null])))
const lowByStock = computed(() => new Map(fiftyTwoWeek.value.map((r) => [r.stock_id, r.pct_from_low])))

const results = computed(() => {
  if (activePreset.value === 'momentum') {
    return stocks.value.filter((s) => {
      const rsi = rsiByStock.value.get(s.id)
      const signal = s.latest_signal?.signal
      return rsi !== null && rsi >= 50 && rsi <= 70 && ['buy', 'strong_buy'].includes(signal) && (s.change_pct ?? 0) >= 2
    })
  }
  if (activePreset.value === 'oversold') {
    return stocks.value.filter((s) => (rsiByStock.value.get(s.id) ?? 100) < 30)
  }
  if (activePreset.value === 'overbought') {
    return stocks.value.filter((s) => (rsiByStock.value.get(s.id) ?? 0) > 70)
  }
  if (activePreset.value === 'near-low') {
    return stocks.value.filter((s) => {
      const pct = lowByStock.value.get(s.id)
      return pct !== null && pct !== undefined && pct <= 5
    })
  }
  return []
})

const { sorted, toggleSort, sortIndicator } = useSortableTable(results, {
  valueGetters: {
    close: (s) => (s.latest_price?.close_price !== undefined ? Number(s.latest_price.close_price) : null),
    rsi: (s) => rsiByStock.value.get(s.id) ?? null,
    signal: (s) => s.latest_signal?.signal ?? null,
  },
})

const {
  sorted: sortedLongTerm,
  toggleSort: toggleLongTermSort,
  sortIndicator: longTermSortIndicator,
} = useSortableTable(longTerm, { defaultKey: 'long_term_score', defaultDir: 'desc' })

function changeTone(pct) {
  if (pct === null || pct === undefined) return ''
  return pct > 0 ? 'positive' : pct < 0 ? 'negative' : ''
}

async function load() {
  loading.value = true
  const [screenerRes, weekRes] = await Promise.all([client.get('/market/screener'), client.get('/market/52-week')])
  stocks.value = screenerRes.data
  fiftyTwoWeek.value = weekRes.data
  loading.value = false
}

// This preset's score is a real backend computation (dividend/right-share/
// price-history aggregates across the whole market) rather than a filter
// over data already on the page — fetched once, lazily, only if someone
// actually picks this preset.
async function loadLongTerm() {
  if (longTerm.value.length || loadingLongTerm.value) return
  loadingLongTerm.value = true
  try {
    const { data } = await client.get('/reports/long-term')
    longTerm.value = data.candidates
  } finally {
    loadingLongTerm.value = false
  }
}

watch(activePreset, (key) => {
  if (key === 'long-term') loadLongTerm()
})

onMounted(load)
</script>

<template>
  <div>
    <h1>Preset Screens</h1>
    <p class="muted">One-click screens built from real, already-tracked data (RSI, signals, 52-week range) — not fitted or backtested, just common-sense filters.</p>

    <div class="filters">
      <button v-for="p in PRESETS" :key="p.key" class="btn-secondary btn" :class="{ active: activePreset === p.key }" @click="activePreset = p.key">
        {{ p.label }}
      </button>
    </div>

    <p class="muted" style="margin-top: 8px">{{ PRESETS.find((p) => p.key === activePreset)?.description }}</p>

    <p v-if="loading" class="muted">Loading…</p>

    <div v-else-if="activePreset === 'long-term'" class="card" style="margin-top: 12px">
      <p v-if="loadingLongTerm" class="muted">Scoring every stock (dividends, dilution, price history) — this one takes a bit longer…</p>
      <template v-else>
        <p class="muted">{{ sortedLongTerm.length }} candidate{{ sortedLongTerm.length === 1 ? '' : 's' }} (excludes anything currently Sell/Strong Sell or too illiquid to rank).</p>
        <table class="table">
          <thead>
            <tr>
              <th class="sortable" @click="toggleLongTermSort('symbol')">Symbol {{ longTermSortIndicator('symbol') }}</th>
              <th class="sortable" @click="toggleLongTermSort('sector')">Sector {{ longTermSortIndicator('sector') }}</th>
              <th class="sortable" @click="toggleLongTermSort('close')">Price {{ longTermSortIndicator('close') }}</th>
              <th class="sortable" @click="toggleLongTermSort('long_term_score')">Score {{ longTermSortIndicator('long_term_score') }}</th>
              <th class="sortable" @click="toggleLongTermSort('dividend_years_recorded')">Div. Years {{ longTermSortIndicator('dividend_years_recorded') }}</th>
              <th class="sortable" @click="toggleLongTermSort('avg_total_dividend_pct')">Avg Div % {{ longTermSortIndicator('avg_total_dividend_pct') }}</th>
              <th class="sortable" @click="toggleLongTermSort('right_share_count')">Right Shares {{ longTermSortIndicator('right_share_count') }}</th>
              <th class="sortable" @click="toggleLongTermSort('volatility_pct')">Volatility {{ longTermSortIndicator('volatility_pct') }}</th>
              <th class="sortable" @click="toggleLongTermSort('return_3y_pct')">3yr Return {{ longTermSortIndicator('return_3y_pct') }}</th>
              <th class="sortable" @click="toggleLongTermSort('share_group')">NEPSE Group {{ longTermSortIndicator('share_group') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in sortedLongTerm" :key="s.stock_id" :title="s.reasons.join(' · ')">
              <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
              <td class="muted">{{ s.sector || '—' }}</td>
              <td>{{ formatPrice(s.close) }}</td>
              <td><strong>{{ s.long_term_score }}</strong></td>
              <td class="muted">{{ s.dividend_years_recorded }}</td>
              <td class="muted">{{ s.avg_total_dividend_pct !== null ? `${s.avg_total_dividend_pct}%` : '—' }}</td>
              <td class="muted">{{ s.right_share_count }}</td>
              <td class="muted">{{ s.volatility_pct !== null ? `${s.volatility_pct}%` : 'not enough data' }}</td>
              <td :class="changeTone(s.return_3y_pct)">
                {{ s.return_3y_pct !== null ? `${s.return_3y_pct > 0 ? '+' : ''}${s.return_3y_pct}%` : 'not enough data' }}
              </td>
              <td class="muted">{{ s.share_group || '—' }}</td>
            </tr>
            <tr v-if="sortedLongTerm.length === 0">
              <td colspan="10" class="muted" style="text-align: center; padding: 24px">No candidates right now.</td>
            </tr>
          </tbody>
        </table>
      </template>
    </div>

    <div v-else class="card" style="margin-top: 12px">
      <p class="muted">{{ results.length }} stock{{ results.length === 1 ? '' : 's' }} match.</p>
      <table class="table">
        <thead>
          <tr>
            <th class="sortable" @click="toggleSort('symbol')">Symbol {{ sortIndicator('symbol') }}</th>
            <th class="sortable" @click="toggleSort('company_name')">Company {{ sortIndicator('company_name') }}</th>
            <th class="sortable" @click="toggleSort('close')">Price {{ sortIndicator('close') }}</th>
            <th class="sortable" @click="toggleSort('change_pct')">% Change {{ sortIndicator('change_pct') }}</th>
            <th class="sortable" @click="toggleSort('rsi')">RSI {{ sortIndicator('rsi') }}</th>
            <th class="sortable" @click="toggleSort('signal')">Signal {{ sortIndicator('signal') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in sorted" :key="s.id">
            <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
            <td class="muted">{{ s.company_name }}</td>
            <td>{{ formatPrice(s.latest_price?.close_price) }}</td>
            <td :class="changeTone(s.change_pct)">{{ s.change_pct !== null && s.change_pct !== undefined ? `${s.change_pct > 0 ? '+' : ''}${s.change_pct}%` : '—' }}</td>
            <td>{{ rsiByStock.get(s.id) !== null && rsiByStock.get(s.id) !== undefined ? rsiByStock.get(s.id).toFixed(1) : '—' }}</td>
            <td>
              <span v-if="s.latest_signal" class="badge" :class="s.latest_signal.signal">{{ s.latest_signal.signal.replace('_', ' ') }}</span>
              <span v-else class="muted">No data</span>
            </td>
          </tr>
          <tr v-if="results.length === 0">
            <td colspan="6" class="muted" style="text-align: center; padding: 24px">No stocks match this screen right now.</td>
          </tr>
        </tbody>
      </table>
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

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}
</style>
