<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'

const rows = ref([])
const loading = ref(true)

async function load() {
  loading.value = true
  const { data } = await client.get('/signals/actionable', { params: { bias: 'buy' } })
  rows.value = data
  loading.value = false
}

function biasMismatch(row) {
  return row.trade_setup && row.trade_setup.bias === 'bearish'
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Buy Signals</h1>
    <p class="muted">
      Every stock currently flagged Buy or Strong Buy, ranked by score — highest conviction first. Target and stop-loss
      come from each stock's own support/resistance and ATR — not financial advice.
    </p>

    <p v-if="loading" class="muted">Loading…</p>

    <div v-else class="card">
      <table class="table" v-if="rows.length">
        <thead>
          <tr>
            <th>Symbol</th><th>Company</th><th>Price</th><th>Signal</th>
            <th>Target</th><th>Stop-Loss</th><th>R:R</th><th>Reasons</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in rows" :key="s.stock_id">
            <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
            <td class="muted">{{ s.company_name }}</td>
            <td>Rs. {{ formatPrice(s.close) }}</td>
            <td><span class="badge" :class="s.signal">{{ s.signal.replace('_', ' ') }}</span></td>
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
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">No buy-leaning signals right now.</p>
    </div>
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
