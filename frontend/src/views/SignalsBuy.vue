<script setup>
import { onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import BuyPlanPanel from '../components/stock/BuyPlanPanel.vue'
import { usePortfolioStore } from '../stores/portfolio'
import { formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const portfolio = usePortfolioStore()
const rows = ref([])
const loading = ref(true)
const planFor = ref(null) // symbol whose buy plan is open

const table = useSortableTable(rows, {
  defaultKey: 'score',
  defaultDir: 'desc',
  valueGetters: {
    close: (s) => (s.close !== null ? Number(s.close) : null),
    target: (s) => s.trade_setup?.target ?? null,
    stop_loss: (s) => s.trade_setup?.stop_loss ?? null,
    risk_reward_ratio: (s) => s.trade_setup?.risk_reward_ratio ?? null,
  },
})
const { sorted } = table

async function load() {
  loading.value = true
  rows.value = await marketApi.actionableSignals('buy')
  loading.value = false
}

function biasMismatch(row) {
  return row.trade_setup && row.trade_setup.bias === 'bearish'
}

onMounted(async () => {
  await Promise.all([load(), portfolio.fetchPortfolios()])
})
</script>

<template>
  <div>
    <PageHeader title="Buy Signals">
      Every stock currently flagged Buy or Strong Buy, ranked by score — highest conviction first. A signal alone never
      buys: use "Plan buy" to run it through the risk, portfolio and cash checks and get the entry, stop-loss, target and
      position size for your portfolio. The Target / Stop-Loss columns here are an indicative read from ATR and support/resistance
      — the plan's numbers are the ones that count. Not financial advice.
    </PageHeader>

    <BuyPlanPanel
      v-if="planFor && portfolio.activePortfolioId"
      :key="planFor"
      :portfolio-id="portfolio.activePortfolioId"
      :symbol="planFor"
      @close="planFor = null"
    />
    <p v-else-if="planFor" class="muted">Create a portfolio first (Portfolio page) to plan a buy.</p>

    <LoadingState v-if="loading" />

    <Card v-else>
      <table v-align-numbers class="table" v-if="rows.length">
        <thead>
          <tr>
            <SortableTh :table="table" column="symbol">Symbol</SortableTh>
            <SortableTh :table="table" column="company_name">Company</SortableTh>
            <SortableTh :table="table" column="close">Price</SortableTh>
            <SortableTh :table="table" column="signal">Signal</SortableTh>
            <SortableTh :table="table" column="target">Indicative Target</SortableTh>
            <SortableTh :table="table" column="stop_loss">Indicative Stop</SortableTh>
            <SortableTh :table="table" column="risk_reward_ratio">R:R</SortableTh>
            <th>Reasons</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in sorted" :key="s.stock_id">
            <td><StockLink :symbol="s.symbol" /></td>
            <td class="muted">{{ s.company_name }}</td>
            <td>Rs. {{ formatPrice(s.close) }}</td>
            <td><SignalBadge :signal="s.signal" /></td>
            <template v-if="s.trade_setup">
              <td class="positive">Rs. {{ formatPrice(s.trade_setup.target) }}</td>
              <td class="negative">Rs. {{ formatPrice(s.trade_setup.stop_loss) }}</td>
              <td :class="s.trade_setup.attractive ? 'positive' : 'muted'">
                {{ s.trade_setup.risk_reward_ratio !== null ? `1:${s.trade_setup.risk_reward_ratio}` : '—' }}
              </td>
            </template>
            <template v-else>
              <td class="muted" colspan="3">Not enough history for a target/stop yet</td>
            </template>
            <td>
              <p v-if="biasMismatch(s)" class="mismatch">
                ⚠ Technical trend actually reads bearish — this "Buy" fired on a shorter-term signal, not the broader trend.
              </p>
              <ul class="reasons">
                <li v-for="(r, i) in s.reasons" :key="i">{{ r }}</li>
              </ul>
            </td>
            <td><button class="btn-secondary btn" @click="planFor = s.symbol">Plan buy</button></td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-else>No buy-leaning signals right now.</EmptyState>
    </Card>
  </div>
</template>

<style scoped>
.reasons {
  margin: 0;
  padding-left: 18px;
  font-size: 0.8rem;
  color: var(--text-muted);
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}

.mismatch {
  margin: 0 0 6px;
  font-size: 0.78rem;
  color: var(--sell);
  font-weight: 600;
}
</style>
