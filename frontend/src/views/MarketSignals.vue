<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import * as marketApi from '../api/market'
import TradePlanPanel from '../components/stock/TradePlanPanel.vue'
import { usePortfolioStore } from '../stores/portfolio'
import { formatPrice } from '../utils/format'
import { useColumnOptions } from '../composables/useColumnOptions'
import { useSortableTable } from '../composables/useSortableTable'

// The Signals page: every stock's latest Buy / Sell / Hold with the percentages behind it. The filters work on the
// loaded list, so changing one is instant; the Buy / Sell price targets are fetched the first time that signal is picked.
const route = useRoute()
const router = useRouter()
const portfolio = usePortfolioStore()

// Screen Options: which columns this page shows, saved in this browser. A filter whose column is switched off is
// hidden and ignored, and the Target / Stop / R:R columns (a heavier calculation) are only requested while on.
const columns = useColumnOptions('signals', [
  { key: 'symbol', label: 'Symbol', locked: true },
  { key: 'company_name', label: 'Company' },
  { key: 'sector', label: 'Sector' },
  { key: 'price', label: 'Price' },
  { key: 'signal', label: 'Signal', locked: true },
  { key: 'buy_pct', label: 'Buy %' },
  { key: 'sell_pct', label: 'Sell %' },
  { key: 'hold_pct', label: 'Hold %' },
  { key: 'hold_reason', label: 'Hold reason' },
  { key: 'setup', label: 'Target / Stop / R:R' },
  { key: 'reasons', label: 'Reasons' },
  { key: 'trade_plan', label: 'Trade plan button' },
])
const show = (key) => columns.isVisible(key)

const SIGNALS = [
  { value: '', label: 'All' },
  { value: 'buy', label: 'Buy' },
  { value: 'hold', label: 'Hold' },
  { value: 'sell', label: 'Sell' },
]
const CONFIDENCE = [
  { value: '', label: 'Any confidence' },
  { value: '50', label: '50% or more' },
  { value: '60', label: '60% or more' },
  { value: '70', label: '70% or more' },
]
const HOLD_TYPES = [
  { value: '', label: 'Any hold reason' },
  { value: 'long_term', label: 'Long-term strength' },
  { value: 'consolidation', label: 'Consolidation' },
  { value: 'wait_confirmation', label: 'Waiting for confirmation' },
  { value: 'profit_protection', label: 'Profit protection' },
  { value: 'temporary_weakness', label: 'Temporary weakness' },
  { value: 'overbought', label: 'Overbought' },
]
const HOLD_TYPE_LABELS = Object.fromEntries(HOLD_TYPES.filter((h) => h.value).map((h) => [h.value, h.label]))

const board = ref([])
const loading = ref(true)
const error = ref('')
const setups = ref({}) // symbol => trade_setup, for the Buy / Sell lists already fetched
const fetchedSides = new Set()
const planFor = ref(null)

const signal = ref(SIGNALS.some((s) => s.value === route.query.signal) ? route.query.signal : 'buy')
const search = ref('')
const sector = ref('')
const confidence = ref('')
const holdType = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    board.value = await marketApi.signalBoard()
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not load the signals.'
  } finally {
    loading.value = false
  }
}

// Buy / Sell price targets come from a heavier per-stock calculation, so they are only fetched for the side being looked at.
async function loadSetups(side) {
  if (fetchedSides.has(side)) return
  fetchedSides.add(side)
  try {
    const list = await marketApi.actionableSignals(side)
    const next = { ...setups.value }
    list.forEach((s) => {
      if (s.trade_setup) next[s.symbol] = s.trade_setup
    })
    setups.value = next
  } catch {
    fetchedSides.delete(side) // try again next time it is picked
  }
}

// Fetch the targets when a Buy / Sell list is shown AND its column is on (or is switched on later).
function ensureSetups() {
  if (show('setup') && (signal.value === 'buy' || signal.value === 'sell')) loadSetups(signal.value)
}

watch(signal, (value) => {
  router.replace({ query: value ? { signal: value } : {} })
  ensureSetups()
  if (value !== 'hold' && value !== '') holdType.value = ''
})
watch(() => show('setup'), ensureSetups)

onMounted(async () => {
  await Promise.all([load(), portfolio.fetchPortfolios()])
  ensureSetups()
})

const confidenceOf = (row) => row[`${row.signal}_pct`] ?? null
const sectorOf = (row) => row.sector || 'No Sector'

const counts = computed(() => {
  const c = { '': board.value.length, buy: 0, hold: 0, sell: 0 }
  board.value.forEach((r) => {
    c[r.signal] = (c[r.signal] || 0) + 1
  })
  return c
})

const sectorOptions = computed(() => [
  { value: '', label: 'All sectors' },
  ...Array.from(new Set(board.value.map(sectorOf))).sort().map((s) => ({ value: s, label: s })),
])

// A filter belongs to a column: switch the column off and the filter goes with it.
const sectorFilterOn = computed(() => show('sector'))
const confidenceFilterOn = computed(() => show('buy_pct') || show('sell_pct') || show('hold_pct'))
const holdFilterOn = computed(() => showHoldReason.value)

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase()
  const min = confidence.value === '' ? null : Number(confidence.value)

  return board.value.filter((r) => {
    if (signal.value && r.signal !== signal.value) return false
    if (term && !(r.symbol.toLowerCase().includes(term) || (r.company_name || '').toLowerCase().includes(term))) return false
    if (sectorFilterOn.value && sector.value && sectorOf(r) !== sector.value) return false
    if (confidenceFilterOn.value && min !== null && !((confidenceOf(r) ?? -1) >= min)) return false
    if (holdFilterOn.value && holdType.value && r.hold_type !== holdType.value) return false
    return true
  })
})

const activeFilters = computed(
  () => [search.value.trim(), sectorFilterOn.value && sector.value, confidenceFilterOn.value && confidence.value, holdFilterOn.value && holdType.value].filter(Boolean).length
)

function clearFilters() {
  search.value = ''
  sector.value = ''
  confidence.value = ''
  holdType.value = ''
}

const showSetup = computed(() => (signal.value === 'buy' || signal.value === 'sell') && show('setup'))
const showHoldReason = computed(() => (signal.value === 'hold' || signal.value === '') && show('hold_reason'))

const table = useSortableTable(filtered, {
  defaultKey: 'confidence',
  defaultDir: 'desc',
  valueGetters: {
    close: (r) => (r.close !== null ? Number(r.close) : null),
    sector: (r) => sectorOf(r),
    confidence: (r) => confidenceOf(r),
    target: (r) => setups.value[r.symbol]?.target ?? null,
    stop_loss: (r) => setups.value[r.symbol]?.stop_loss ?? null,
    risk_reward_ratio: (r) => setups.value[r.symbol]?.risk_reward_ratio ?? null,
  },
})
const { sorted } = table

// The rule lines only: the "BUY: BUY 59.3% · SELL ..." summary repeats what the percentage columns already show.
const readableReasons = (row) => (row.reasons || []).filter((x) => !/^(BUY|SELL|HOLD): BUY |^Decision (BUY|SELL) — /.test(x)).slice(0, 3)

const pct = (v) => (v === null || v === undefined ? '—' : `${Number(v).toFixed(1)}%`)
// Visible columns, for the "no match" row to span.
const colCount = computed(
  () => 2 + ['company_name', 'sector', 'price', 'buy_pct', 'sell_pct', 'hold_pct', 'reasons', 'trade_plan'].filter(show).length + (showHoldReason.value ? 1 : 0) + (showSetup.value ? 3 : 0)
)
</script>

<template>
  <div>
    <ScreenOptions
      title="Columns"
      :options="columns.options"
      :visible="columns.visibleKeys.value"
      note="Your choice is saved in this browser. A filter disappears with its column, and the Target / Stop / R:R figures are only worked out while that column is on."
      @toggle="columns.toggle"
      @reset="columns.reset"
    />

    <TradePlanPanel v-if="planFor && portfolio.activePortfolioId" :key="planFor" :portfolio-id="portfolio.activePortfolioId" :symbol="planFor" @close="planFor = null" />
    <p v-else-if="planFor" class="muted">Create a portfolio first (Portfolio page) to see a trade plan.</p>

    <LoadingState v-if="loading" />

    <Card v-else-if="error">
      <EmptyState>{{ error }}</EmptyState>
      <button class="btn" @click="load">Try again</button>
    </Card>

    <Card v-else-if="!board.length">
      <EmptyState>No signals yet. They appear after prices have been fetched and the generate/indicators and generate/signals crons have run.</EmptyState>
    </Card>

    <template v-else>
      <div class="filters">
        <div class="filter-row">
          <div class="field">
            <label>Signal</label>
            <div class="chips" role="group" aria-label="Signal">
              <button v-for="s in SIGNALS" :key="s.value" type="button" class="chip" :class="[s.value, { on: signal === s.value }]" @click="signal = s.value">
                {{ s.label }} <span class="count">{{ counts[s.value] ?? 0 }}</span>
              </button>
            </div>
          </div>

          <div class="field">
            <label>Search</label>
            <input v-model="search" class="input search" placeholder="Symbol or company…" />
          </div>
          <div v-if="sectorFilterOn" class="field">
            <label>Sector</label>
            <SearchableSelect v-model="sector" :options="sectorOptions" style="min-width: 190px" />
          </div>
          <div v-if="confidenceFilterOn" class="field">
            <label>Confidence</label>
            <SearchableSelect v-model="confidence" :options="CONFIDENCE" style="min-width: 160px" />
          </div>
          <div v-if="holdFilterOn" class="field">
            <label>Hold reason</label>
            <SearchableSelect v-model="holdType" :options="HOLD_TYPES" style="min-width: 200px" />
          </div>
        </div>

        <div class="filter-foot">
          <button v-if="activeFilters" type="button" class="clear" @click="clearFilters">Clear {{ activeFilters }} filter{{ activeFilters === 1 ? '' : 's' }}</button>
          <span class="muted small">Confidence = the Buy % of a Buy, the Sell % of a Sell, the Hold % of a Hold. Click a stock to see how its decision was reached.</span>
          <span class="muted result-count">{{ filtered.length }} of {{ board.length }} stocks</span>
        </div>
      </div>

      <Card>
        <table v-align-numbers class="table">
          <thead>
            <tr>
              <SortableTh :table="table" column="symbol">Symbol</SortableTh>
              <SortableTh v-if="show('company_name')" :table="table" column="company_name">Company</SortableTh>
              <SortableTh v-if="show('sector')" :table="table" column="sector">Sector</SortableTh>
              <SortableTh v-if="show('price')" :table="table" column="close">Price</SortableTh>
              <SortableTh :table="table" column="signal">Signal</SortableTh>
              <SortableTh v-if="show('buy_pct')" :table="table" column="buy_pct">Buy %</SortableTh>
              <SortableTh v-if="show('sell_pct')" :table="table" column="sell_pct">Sell %</SortableTh>
              <SortableTh v-if="show('hold_pct')" :table="table" column="hold_pct">Hold %</SortableTh>
              <th v-if="showHoldReason">Hold reason</th>
              <template v-if="showSetup">
                <SortableTh :table="table" column="target">Indicative Target</SortableTh>
                <SortableTh :table="table" column="stop_loss">Indicative Stop</SortableTh>
                <SortableTh :table="table" column="risk_reward_ratio">R:R</SortableTh>
              </template>
              <th v-if="show('reasons')">Reasons</th>
              <th v-if="show('trade_plan')"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in sorted" :key="r.stock_id">
              <td><StockLink :symbol="r.symbol" /></td>
              <td v-if="show('company_name')" class="muted">{{ r.company_name }}</td>
              <td v-if="show('sector')" class="muted">{{ sectorOf(r) }}</td>
              <td v-if="show('price')">Rs. {{ formatPrice(r.close) }}</td>
              <td><SignalBadge :signal="r.signal" /></td>
              <td v-if="show('buy_pct')" :class="{ positive: r.signal === 'buy' }">{{ pct(r.buy_pct) }}</td>
              <td v-if="show('sell_pct')" :class="{ negative: r.signal === 'sell' }">{{ pct(r.sell_pct) }}</td>
              <td v-if="show('hold_pct')" :class="{ muted: r.signal !== 'hold' }">{{ pct(r.hold_pct) }}</td>
              <td v-if="showHoldReason" class="muted">{{ r.signal === 'hold' ? HOLD_TYPE_LABELS[r.hold_type] || '—' : '' }}</td>
              <template v-if="showSetup">
                <template v-if="setups[r.symbol]">
                  <td class="positive">Rs. {{ formatPrice(setups[r.symbol].target) }}</td>
                  <td class="negative">Rs. {{ formatPrice(setups[r.symbol].stop_loss) }}</td>
                  <td :class="setups[r.symbol].attractive ? 'positive' : 'muted'">
                    {{ setups[r.symbol].risk_reward_ratio !== null ? `1:${setups[r.symbol].risk_reward_ratio}` : '—' }}
                  </td>
                </template>
                <td v-else class="muted" colspan="3">{{ fetchedSides.has(r.signal) ? 'Not enough history for a target / stop yet' : 'Loading…' }}</td>
              </template>
              <td v-if="show('reasons')">
                <ul class="reasons">
                  <li v-for="(reason, i) in readableReasons(r)" :key="i">{{ reason }}</li>
                </ul>
              </td>
              <td v-if="show('trade_plan')"><button v-if="r.signal === 'buy'" class="btn-secondary btn" @click="planFor = r.symbol">Trade plan</button></td>
            </tr>
            <tr v-if="!sorted.length">
              <td :colspan="colCount" class="muted" style="text-align: center; padding: 24px">No stocks match these filters.</td>
            </tr>
          </tbody>
        </table>
      </Card>
    </template>
  </div>
</template>

<style scoped>
.filters {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 16px;
  padding: 14px 16px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface);
}

.filter-row {
  display: flex;
  gap: 10px 20px;
  align-items: flex-end;
  flex-wrap: wrap;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.field label {
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.search {
  width: 220px;
}

.chips {
  display: inline-flex;
  border: 1px solid var(--border);
  border-radius: 8px;
  overflow: hidden;
}

.chip {
  border: 0;
  border-right: 1px solid var(--border);
  background: transparent;
  color: var(--text-muted);
  padding: 6px 12px;
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
}

.chip:last-child {
  border-right: 0;
}

.chip.on {
  background: var(--primary-soft);
  color: var(--primary);
  font-weight: 600;
}

.chip.buy.on {
  background: var(--buy-bg);
  color: var(--buy);
}

.chip.sell.on {
  background: var(--sell-bg);
  color: var(--sell);
}

.count {
  margin-left: 4px;
  font-size: 0.75rem;
  opacity: 0.8;
}

.filter-foot {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
}

.clear {
  border: 0;
  background: none;
  color: var(--primary);
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
  padding: 0;
}

.result-count {
  margin-left: auto;
}

.reasons {
  margin: 0;
  padding-left: 18px;
  min-width: 300px;
  font-size: 0.8rem;
  color: var(--text-muted);
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}
</style>
