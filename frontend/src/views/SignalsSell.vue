<script setup>
import { onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import { formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const rows = ref([])
const loading = ref(true)

const table = useSortableTable(rows, {
  defaultKey: 'score',
  defaultDir: 'asc',
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
  rows.value = await marketApi.actionableSignals('sell')
  loading.value = false
}

function biasMismatch(row) {
  return row.trade_setup && row.trade_setup.bias === 'bullish'
}

onMounted(load)
</script>

<template>
  <div>
    <p class="muted">
      Every stock currently flagged Sell or Strong Sell, ranked by score — highest conviction first. NEPSE doesn't
      allow short-selling, so this is for existing holders deciding whether to exit: "Target" is how far it may still
      fall, "Invalidation" is the level above which the bearish read would be wrong. Not financial advice.
    </p>

    <LoadingState v-if="loading" />

    <Card v-else>
      <table v-align-numbers class="table" v-if="rows.length">
        <thead>
          <tr>
            <SortableTh :table="table" column="symbol">Symbol</SortableTh>
            <SortableTh :table="table" column="company_name">Company</SortableTh>
            <SortableTh :table="table" column="close">Price</SortableTh>
            <SortableTh :table="table" column="signal">Signal</SortableTh>
            <SortableTh :table="table" column="target">Target</SortableTh>
            <SortableTh :table="table" column="stop_loss">Invalidation</SortableTh>
            <SortableTh :table="table" column="risk_reward_ratio">R:R</SortableTh>
            <th>Reasons</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in sorted" :key="s.stock_id">
            <td><StockLink :symbol="s.symbol" /></td>
            <td class="muted">{{ s.company_name }}</td>
            <td>Rs. {{ formatPrice(s.close) }}</td>
            <td><SignalBadge :signal="s.signal" /></td>
            <template v-if="s.trade_setup">
              <td>Rs. {{ formatPrice(s.trade_setup.target) }}</td>
              <td class="muted">Rs. {{ formatPrice(s.trade_setup.stop_loss) }}</td>
              <td :class="s.trade_setup.attractive ? 'positive' : 'muted'">
                {{ s.trade_setup.risk_reward_ratio !== null ? `1:${s.trade_setup.risk_reward_ratio}` : '—' }}
              </td>
            </template>
            <template v-else>
              <td class="muted" colspan="3">Not enough history for a target/stop yet</td>
            </template>
            <td>
              <p v-if="biasMismatch(s)" class="mismatch">
                ⚠ Technical trend actually reads bullish — this "Sell" fired on a shorter-term signal, not the broader trend.
              </p>
              <ul class="reasons">
                <li v-for="(r, i) in s.reasons" :key="i">{{ r }}</li>
              </ul>
            </td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-else>No sell-leaning signals right now.</EmptyState>
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

.mismatch {
  margin: 0 0 6px;
  font-size: 0.78rem;
  color: var(--sell);
  font-weight: 600;
}
</style>
