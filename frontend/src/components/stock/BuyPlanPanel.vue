<!--
  What a stock's BUY signal becomes for the active portfolio: the checks it has
  to pass (signal, risk, portfolio, cash, sizing) and, if they all pass, the
  entry / stop / target / quantity, with a button to place the pending order.
  Nothing is bought here — the order is a plan; the purchase is still recorded
  as a transaction on the Portfolio page.

  <BuyPlanPanel :portfolio-id="1" symbol="NRN" @close="…" @placed="…" />
-->
<script setup>
import { onMounted, ref } from 'vue'
import * as portfolioApi from '../../api/portfolio'
import { formatPrice } from '../../utils/format'

const props = defineProps({
  portfolioId: { type: Number, required: true },
  symbol: { type: String, required: true },
})
const emit = defineEmits(['close', 'placed'])

const loading = ref(true)
const result = ref(null)
const error = ref('')
const placing = ref(false)
const placed = ref(false)

const CHECK_LABELS = { signal: 'Signal', risk: 'Risk / reward', portfolio: 'Portfolio', cash: 'Cash', sizing: 'Position size' }

onMounted(async () => {
  try {
    result.value = await portfolioApi.buyOrderPreview(props.portfolioId, props.symbol)
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not check this buy.'
  } finally {
    loading.value = false
  }
})

async function place() {
  placing.value = true
  error.value = ''
  try {
    await portfolioApi.placeBuyOrder(props.portfolioId, props.symbol)
    placed.value = true
    emit('placed')
  } catch (e) {
    error.value = e.response?.data?.message || 'Could not place the order.'
  } finally {
    placing.value = false
  }
}
</script>

<template>
  <Card :title="`Buy plan — ${symbol}`" style="margin-bottom: 16px">
    <LoadingState v-if="loading" />
    <template v-else-if="result">
      <ul class="checks">
        <li v-for="c in result.checks" :key="c.name" :class="c.passed ? 'ok' : 'fail'">
          <strong>{{ c.passed ? '✓' : '✗' }} {{ CHECK_LABELS[c.name] ?? c.name }}</strong>
          <span class="muted"> — {{ c.detail }}</span>
        </li>
      </ul>

      <div v-if="result.approved" class="plan">
        <div><span class="muted">Entry</span> Rs. {{ formatPrice(result.plan.entry_price) }}</div>
        <div><span class="muted">Stop-loss</span> <span class="negative">Rs. {{ formatPrice(result.plan.stop_loss) }}</span></div>
        <div><span class="muted">Target</span> <span class="positive">Rs. {{ formatPrice(result.plan.target_price) }}</span></div>
        <div><span class="muted">Risk / reward</span> 1:{{ result.plan.risk_reward }}</div>
        <div><span class="muted">Quantity</span> {{ result.plan.quantity }} shares</div>
        <div><span class="muted">Cost</span> Rs. {{ formatPrice(result.plan.position_value) }} + {{ formatPrice(result.plan.fees) }} fees</div>
        <div><span class="muted">Max loss at stop</span> Rs. {{ formatPrice(result.plan.risk_amount) }}</div>
      </div>
      <p v-else class="negative"><strong>Not approved:</strong> {{ result.reason }}</p>
    </template>

    <p v-if="error" class="error-text">{{ error }}</p>
    <p v-if="placed" class="positive">Order placed. Record the purchase as a transaction on the Portfolio page once you buy.</p>

    <div class="row">
      <button v-if="result?.approved && !placed" class="btn" :disabled="placing" @click="place">{{ placing ? 'Placing…' : 'Place buy order' }}</button>
      <button class="btn-secondary btn" @click="emit('close')">Close</button>
    </div>
  </Card>
</template>

<style scoped>
.checks {
  list-style: none;
  margin: 0 0 12px;
  padding: 0;
  font-size: 0.85rem;
}

.checks li {
  padding: 3px 0;
}

.ok strong {
  color: var(--strong-buy);
}

.fail strong {
  color: var(--strong-sell);
}

.plan {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 8px 16px;
  margin-bottom: 12px;
}

.plan .muted {
  display: block;
  font-size: 0.72rem;
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}

.row {
  display: flex;
  gap: 10px;
}
</style>
