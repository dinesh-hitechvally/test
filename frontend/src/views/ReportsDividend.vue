<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'
import StatCard from '../components/StatCard.vue'
import SearchableSelect from '../components/SearchableSelect.vue'
import { useSortableTable } from '../composables/useSortableTable'

const sectors = ref([])
const selectedSector = ref('')
const report = ref(null)
const loading = ref(true)
const error = ref('')

const sectorOptions = computed(() => [
  { value: '', label: 'All sectors' },
  ...sectors.value.map((s) => ({ value: s.sector, label: `${s.sector} (${s.stock_count})` })),
])

const sortOptions = [
  { value: 'dividend_yield_pct', label: 'Sort by cash yield' },
  { value: 'actual_total_yield_pct', label: 'Sort by actual total yield' },
  { value: 'latest_total_pct', label: 'Sort by latest declared total %' },
  { value: 'avg_total_dividend_pct', label: 'Sort by avg total % (history)' },
  { value: 'years_recorded', label: 'Sort by years recorded' },
]

async function loadSectors() {
  const { data } = await client.get('/reports/sectors')
  sectors.value = data
}

async function loadReport() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await client.get('/reports/dividends', {
      params: selectedSector.value ? { sector: selectedSector.value } : {},
    })
    report.value = data
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load dividend report.'
    report.value = null
  } finally {
    loading.value = false
  }
}

const {
  sorted: sortedStocks,
  sortKey,
  sortDir,
  toggleSort,
  sortIndicator,
} = useSortableTable(
  computed(() => report.value?.stocks ?? []),
  { defaultKey: 'dividend_yield_pct', defaultDir: 'desc' }
)

// The "Sort by ..." dropdown is a shortcut into the same sort state that
// clicking a column header drives — picking an option here always sorts
// descending (matching what it always did), while header clicks toggle.
function onSortOptionChange() {
  sortDir.value = 'desc'
}

watch(selectedSector, loadReport)

onMounted(async () => {
  await loadSectors()
  await loadReport()
})
</script>

<template>
  <div>
    <h1>Dividend Report</h1>
    <p class="muted">
      Every stock with recorded dividend/bonus history, ranked by trailing dividend yield (cash dividend as a % of
      market price — bonus shares aren't included since they're not a cash return).
    </p>

    <div class="card">
      <div style="display: flex; gap: 16px; flex-wrap: wrap">
        <SearchableSelect v-model="selectedSector" :options="sectorOptions" style="max-width: 280px" />
        <SearchableSelect v-model="sortKey" :options="sortOptions" style="max-width: 240px" @change="onSortOptionChange" />
      </div>
    </div>

    <p v-if="loading" class="muted" style="margin-top: 16px">Loading…</p>
    <p v-else-if="error" class="muted" style="margin-top: 16px">{{ error }}</p>

    <template v-else-if="report">
      <div class="grid grid-cards" style="margin-top: 16px">
        <StatCard label="Stocks with Dividend Data" :value="report.totals.stocks_with_dividends" />
        <StatCard
          label="Avg Yield"
          :value="report.totals.avg_yield_pct !== null ? `${report.totals.avg_yield_pct}%` : '—'"
        />
        <StatCard
          label="Top Yield"
          :value="report.totals.top_yield_pct !== null ? `${report.totals.top_yield_pct}%` : '—'"
          tone="positive"
        />
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>Top Dividend Picks</h3>
        <p class="muted" style="margin-top: -6px">
          Not financial advice — a transparent, rule-based ranking: 50% trailing yield, 30% payout consistency
          (years recorded), 20% historical average total payout. Requires an actual cash yield (bonus-only years
          don't count) and excludes anything currently flagged Sell or Strong Sell.
        </p>
        <table class="table" v-if="report.top_picks.length">
          <thead>
            <tr><th>#</th><th>Symbol</th><th>Score</th><th>Signal</th><th>Why</th></tr>
          </thead>
          <tbody>
            <tr v-for="(p, i) in report.top_picks" :key="p.stock_id">
              <td class="muted">{{ i + 1 }}</td>
              <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: p.symbol } }">{{ p.symbol }}</RouterLink></td>
              <td><strong>{{ p.pick_score }}</strong></td>
              <td>
                <span v-if="p.latest_signal" class="badge" :class="p.latest_signal">{{ p.latest_signal.replace('_', ' ') }}</span>
                <span v-else class="muted">—</span>
              </td>
              <td class="muted">{{ p.reasons.join(' · ') }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">
          No stocks currently qualify (need a real cash yield and a non-bearish signal) — check back as more
          dividend history gets pulled in.
        </p>
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>Ranked by {{ sortKey.replace(/_/g, ' ') }}</h3>
        <table class="table" v-if="sortedStocks.length">
          <thead>
            <tr>
              <th class="sortable" @click="toggleSort('symbol')">Symbol {{ sortIndicator('symbol') }}</th>
              <th class="sortable" @click="toggleSort('sector')">Sector {{ sortIndicator('sector') }}</th>
              <th class="sortable" @click="toggleSort('close')">Price {{ sortIndicator('close') }}</th>
              <th class="sortable" @click="toggleSort('latest_fiscal_year')">Latest FY {{ sortIndicator('latest_fiscal_year') }}</th>
              <th class="sortable" @click="toggleSort('latest_cash_pct')">Cash {{ sortIndicator('latest_cash_pct') }}</th>
              <th class="sortable" @click="toggleSort('latest_bonus_pct')">Bonus {{ sortIndicator('latest_bonus_pct') }}</th>
              <th class="sortable" @click="toggleSort('latest_total_pct')">Declared Total {{ sortIndicator('latest_total_pct') }}</th>
              <th class="sortable" @click="toggleSort('dividend_yield_pct')">Cash Yield {{ sortIndicator('dividend_yield_pct') }}</th>
              <th class="sortable" @click="toggleSort('actual_total_yield_pct')">Actual Total Yield {{ sortIndicator('actual_total_yield_pct') }}</th>
              <th class="sortable" @click="toggleSort('years_recorded')">Years {{ sortIndicator('years_recorded') }}</th>
              <th class="sortable" @click="toggleSort('avg_total_dividend_pct')">Avg Total (history) {{ sortIndicator('avg_total_dividend_pct') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in sortedStocks" :key="s.stock_id">
              <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
              <td class="muted">{{ s.sector || '—' }}</td>
              <td>Rs. {{ formatPrice(s.close) }}</td>
              <td class="muted">{{ s.latest_fiscal_year }}</td>
              <td>{{ s.latest_cash_pct !== null ? `${s.latest_cash_pct}%` : '—' }}</td>
              <td>{{ s.latest_bonus_pct !== null ? `${s.latest_bonus_pct}%` : '—' }}</td>
              <td class="muted" title="Declared against face value, not current market price">
                {{ s.latest_total_pct !== null ? `${s.latest_total_pct}%` : '—' }}
              </td>
              <td :class="s.dividend_yield_pct > 0 ? 'positive' : ''">
                {{ s.dividend_yield_pct !== null ? `${s.dividend_yield_pct}%` : '—' }}
              </td>
              <td :class="s.actual_total_yield_pct > 0 ? 'positive' : ''" title="Cash yield at current price, plus bonus shares (already price-relative — bonus % of shares held is the same % of value regardless of price)">
                <strong>{{ s.actual_total_yield_pct !== null ? `${s.actual_total_yield_pct}%` : '—' }}</strong>
              </td>
              <td class="muted">{{ s.years_recorded }}</td>
              <td class="muted">{{ s.avg_total_dividend_pct !== null ? `${s.avg_total_dividend_pct}%` : '—' }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">
          No dividend data recorded for any stock yet — visit a stock's detail page and click
          "Refresh Dividend/Bonus Data" to pull its history.
        </p>
        <p class="muted small" style="margin-top: 10px">
          "Declared Total" is the raw % against face value as announced (Rs. 100 for most equities, but some
          instruments like mutual fund units use a different face value). "Actual Total Yield" converts that to what
          it's really worth at today's market price — the two can differ a lot once price has moved far from face
          value.
        </p>
      </div>
    </template>
  </div>
</template>

<style scoped>
.positive {
  color: var(--strong-buy);
}

.small {
  font-size: 0.78rem;
}
</style>
