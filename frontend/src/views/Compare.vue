<script setup>
import { computed, onMounted, ref } from 'vue'
import * as stocksApi from '../api/stocks'
import { useStocksStore } from '../stores/stocks'
import { changeTone, formatPrice } from '../utils/format'
import ComparisonChart from '../components/charts/ComparisonChart.vue'

const stocksStore = useStocksStore()
const selected = ref([])
const picker = ref('')
const loading = ref(false)
const stats = ref([])
const chartLabels = ref([])
const chartSeries = ref([])

// Add/remove can fire loadComparison() again before an in-flight call
// resolves; without this, a slower stale request could overwrite a newer
// one's results. Only the most recently *started* call is allowed to commit.
let requestSeq = 0

const availableToAdd = computed(() =>
  stocksStore.stocks.filter((s) => !selected.value.includes(s.symbol))
)

const availableOptions = computed(() =>
  availableToAdd.value.map((s) => ({ value: s.symbol, label: `${s.symbol} — ${s.company_name}` }))
)

function addStock() {
  if (!picker.value || selected.value.length >= 4) return
  selected.value.push(picker.value)
  picker.value = ''
  loadComparison()
}

function removeStock(symbol) {
  selected.value = selected.value.filter((s) => s !== symbol)
  loadComparison()
}

async function loadComparison() {
  const thisRequest = ++requestSeq

  if (selected.value.length === 0) {
    stats.value = []
    chartLabels.value = []
    chartSeries.value = []
    return
  }

  loading.value = true
  try {
    const results = await Promise.all(
      selected.value.map(async (symbol) => {
        const [prices, indicators] = await Promise.all([
          stocksApi.prices(symbol, 180),
          stocksApi.indicators(symbol, 180),
        ])
        return { symbol, prices, indicators }
      })
    )

    const dateSet = new Set()
    results.forEach((r) => r.prices.forEach((p) => dateSet.add(p.trade_date)))
    const labels = Array.from(dateSet).sort()

    const series = results.map((r) => {
      const byDate = Object.fromEntries(r.prices.map((p) => [p.trade_date, Number(p.close_price)]))
      const firstDate = r.prices[0]?.trade_date
      const baseClose = firstDate ? byDate[firstDate] : null

      const data = labels.map((date) => {
        if (!baseClose || date < firstDate || byDate[date] === undefined) return null
        return Number((((byDate[date] - baseClose) / baseClose) * 100).toFixed(2))
      })

      return { symbol: r.symbol, data }
    })

    const statsRows = results.map((r) => {
      const lastPrice = r.prices.at(-1)
      const prevPrice = r.prices.at(-2)
      const changePct = lastPrice && prevPrice ? Number((((lastPrice.close_price - prevPrice.close_price) / prevPrice.close_price) * 100).toFixed(2)) : null
      const lastIndicator = r.indicators.at(-1)
      const stock = stocksStore.stocks.find((s) => s.symbol === r.symbol)

      return {
        symbol: r.symbol,
        company_name: stock?.company_name,
        sector: stock?.sector || 'No Sector',
        price: lastPrice?.close_price ?? null,
        change_pct: changePct,
        rsi: lastIndicator?.rsi_14 ?? null,
        signal: stock?.latest_signal?.signal ?? null,
      }
    })

    if (thisRequest !== requestSeq) return // a newer request started while this one was in flight

    chartLabels.value = labels
    chartSeries.value = series
    stats.value = statsRows
  } finally {
    if (thisRequest === requestSeq) loading.value = false
  }
}

onMounted(async () => {
  if (stocksStore.stocks.length === 0) await stocksStore.fetchStocks()
})
</script>

<template>
  <div>
    <div class="card" style="margin-bottom: 20px">
      <div class="picker-row">
        <SearchableSelect
          v-model="picker"
          :options="availableOptions"
          style="max-width: 320px"
          :disabled="selected.length >= 4"
          :placeholder="selected.length >= 4 ? 'Maximum 4 stocks' : 'Select a stock…'"
        />
        <button class="btn" :disabled="!picker" @click="addStock">Add</button>
      </div>
      <div class="chips" v-if="selected.length">
        <span v-for="symbol in selected" :key="symbol" class="chip">
          {{ symbol }}
          <button class="chip-remove" @click="removeStock(symbol)">×</button>
        </span>
      </div>
    </div>

    <LoadingState v-if="loading">Loading comparison…</LoadingState>

    <template v-else-if="selected.length">
      <div class="card">
        <ComparisonChart :labels="chartLabels" :series="chartSeries" />
      </div>

      <div class="card" style="margin-top: 16px">
        <table v-align-numbers class="table">
          <thead>
            <tr><th>Symbol</th><th>Sector</th><th>Price</th><th>% Change</th><th>RSI (14)</th><th>Signal</th></tr>
          </thead>
          <tbody>
            <tr v-for="s in stats" :key="s.symbol">
              <td><strong>{{ s.symbol }}</strong> <span class="muted">{{ s.company_name }}</span></td>
              <td>{{ s.sector }}</td>
              <td>{{ formatPrice(s.price) }}</td>
              <td :class="changeTone(s.change_pct)">{{ s.change_pct !== null ? `${s.change_pct > 0 ? '+' : ''}${s.change_pct}%` : '—' }}</td>
              <td>{{ s.rsi !== null ? Number(s.rsi).toFixed(1) : '—' }}</td>
              <td>
                <SignalBadge v-if="s.signal" :signal="s.signal" />
                <span v-else class="muted">No data</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <EmptyState v-else>Add at least one stock above to get started.</EmptyState>
  </div>
</template>

<style scoped>
.picker-row {
  display: flex;
  gap: 10px;
}

.chips {
  display: flex;
  gap: 8px;
  margin-top: 12px;
  flex-wrap: wrap;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #eff6ff;
  color: #1d4ed8;
  padding: 5px 6px 5px 12px;
  border-radius: 999px;
  font-size: 0.85rem;
  font-weight: 600;
}

.chip-remove {
  background: none;
  border: none;
  color: #1d4ed8;
  cursor: pointer;
  font-size: 1rem;
  line-height: 1;
  padding: 2px 6px;
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}
</style>
