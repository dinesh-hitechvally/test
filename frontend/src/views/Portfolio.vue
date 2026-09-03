<script setup>
import { onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { usePortfolioStore } from '../stores/portfolio'
import { useStocksStore } from '../stores/stocks'
import { formatPrice } from '../utils/format'
import StatCard from '../components/StatCard.vue'

const store = usePortfolioStore()
const stocksStore = useStocksStore()

const showNewPortfolioForm = ref(false)
const newPortfolioName = ref('')

const showAddForm = ref(false)
const txType = ref('buy')
const txStockId = ref('')
const txQuantity = ref('')
const txPrice = ref('')
const txFees = ref('')
const txDate = ref(new Date().toISOString().slice(0, 10))
const txNotes = ref('')
const txError = ref('')
const submitting = ref(false)

async function loadActive() {
  if (!store.activePortfolioId) return
  await Promise.all([store.fetchDetail(store.activePortfolioId), store.fetchTransactions(store.activePortfolioId)])
}

async function handleCreatePortfolio() {
  if (!newPortfolioName.value.trim()) return
  await store.createPortfolio(newPortfolioName.value.trim())
  newPortfolioName.value = ''
  showNewPortfolioForm.value = false
  await loadActive()
}

function resetTxForm() {
  txType.value = 'buy'
  txStockId.value = ''
  txQuantity.value = ''
  txPrice.value = ''
  txFees.value = ''
  txDate.value = new Date().toISOString().slice(0, 10)
  txNotes.value = ''
  txError.value = ''
}

async function handleAddTransaction() {
  txError.value = ''

  if (!store.activePortfolioId) {
    txError.value = 'Still loading your portfolio — try again in a moment.'
    return
  }

  submitting.value = true
  try {
    await store.addTransaction(store.activePortfolioId, {
      stock_id: txStockId.value,
      type: txType.value,
      quantity: txQuantity.value,
      price: txPrice.value,
      fees: txFees.value || 0,
      transaction_date: txDate.value,
      notes: txNotes.value || null,
    })
    resetTxForm()
    showAddForm.value = false
  } catch (e) {
    txError.value = e.response?.data?.message || 'Could not add transaction.'
  } finally {
    submitting.value = false
  }
}

const deletingId = ref(null)
const deleteError = ref('')

async function handleDelete(tx) {
  const label = `${tx.type === 'buy' ? 'Buy' : 'Sell'} ${tx.quantity} ${tx.stock?.symbol ?? ''} on ${tx.transaction_date}`
  if (!window.confirm(`Delete this transaction?\n\n${label}\n\nThis can't be undone.`)) return

  deleteError.value = ''
  deletingId.value = tx.id
  try {
    await store.deleteTransaction(store.activePortfolioId, tx.id)
  } catch (e) {
    deleteError.value = e.response?.data?.message || 'Could not delete transaction.'
  } finally {
    deletingId.value = null
  }
}

function changeTone(value) {
  if (value === null || value === undefined) return ''
  return value > 0 ? 'positive' : value < 0 ? 'negative' : ''
}

function formatSignal(label) {
  return label.replace('_', ' ')
}

// Only reload on an actual portfolio switch (via the dropdown) — the
// initial assignment from fetchPortfolios() is already handled by the
// explicit loadActive() call in onMounted below, so skip that first
// transition to avoid firing every request twice on page load.
watch(
  () => store.activePortfolioId,
  (newId, oldId) => {
    if (oldId !== null && oldId !== undefined) loadActive()
  }
)

onMounted(async () => {
  if (stocksStore.stocks.length === 0) await stocksStore.fetchStocks()
  await store.fetchPortfolios()
  await loadActive()
})
</script>

<template>
  <div>
    <div class="page-header">
      <h1>Portfolio</h1>
      <div class="actions">
        <select v-if="store.portfolios.length > 1" v-model="store.activePortfolioId" class="input" style="max-width: 220px">
          <option v-for="p in store.portfolios" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <button class="btn-secondary btn" @click="showNewPortfolioForm = !showNewPortfolioForm">New Portfolio</button>
        <button class="btn" :disabled="!store.activePortfolioId" @click="showAddForm = !showAddForm">Add Transaction</button>
      </div>
    </div>

    <p v-if="!store.activePortfolioId" class="muted">Loading your portfolio…</p>

    <div v-if="showNewPortfolioForm" class="card form-stack" style="margin-bottom: 20px; flex-direction: row; align-items: center; max-width: none">
      <input v-model="newPortfolioName" class="input" placeholder="Portfolio name" style="max-width: 260px" />
      <button class="btn" @click="handleCreatePortfolio">Create</button>
    </div>

    <div v-if="showAddForm" class="card form-stack" style="margin-bottom: 20px">
      <h3>Add a transaction</h3>
      <div class="type-toggle">
        <button class="btn-secondary btn" :class="{ active: txType === 'buy' }" @click="txType = 'buy'">Buy</button>
        <button class="btn-secondary btn" :class="{ active: txType === 'sell' }" @click="txType = 'sell'">Sell</button>
      </div>
      <select v-model="txStockId" class="input">
        <option value="" disabled>Select a stock…</option>
        <option v-for="s in stocksStore.stocks" :key="s.id" :value="s.id">{{ s.symbol }} — {{ s.company_name }}</option>
      </select>
      <input v-model="txQuantity" type="number" min="1" class="input" placeholder="Quantity" />
      <input v-model="txPrice" type="number" min="0.01" step="0.01" class="input" placeholder="Price per share (Rs.)" />
      <input v-model="txFees" type="number" min="0" step="0.01" class="input" placeholder="Fees / brokerage (optional)" />
      <input v-model="txDate" type="date" class="input" />
      <input v-model="txNotes" class="input" placeholder="Notes (optional)" />
      <p v-if="txError" class="error-text">{{ txError }}</p>
      <button class="btn" :disabled="submitting" @click="handleAddTransaction">{{ submitting ? 'Saving…' : 'Save' }}</button>
    </div>

    <template v-if="store.detail">
      <div class="grid grid-cards">
        <StatCard label="Total Invested" :value="`Rs. ${formatPrice(store.detail.summary.total_invested)}`" />
        <StatCard label="Current Value" :value="`Rs. ${formatPrice(store.detail.summary.current_value)}`" />
        <StatCard
          label="Unrealized P&L"
          :value="`Rs. ${formatPrice(store.detail.summary.unrealized_pnl)}`"
          :tone="changeTone(store.detail.summary.unrealized_pnl)"
          :sub="store.detail.summary.unrealized_pnl_pct !== null ? `${store.detail.summary.unrealized_pnl_pct}%` : ''"
        />
        <StatCard
          label="Realized P&L"
          :value="`Rs. ${formatPrice(store.detail.summary.realized_pnl)}`"
          :tone="changeTone(store.detail.summary.realized_pnl)"
        />
        <StatCard
          label="Total P&L"
          :value="`Rs. ${formatPrice(store.detail.summary.total_pnl)}`"
          :tone="changeTone(store.detail.summary.total_pnl)"
        />
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>Holdings</h3>
        <table class="table" v-if="store.detail.holdings.length">
          <thead>
            <tr>
              <th>Symbol</th><th>Qty</th><th>Avg Cost</th><th>Invested</th>
              <th>Current Price</th><th>Current Value</th><th>Unrealized P&L</th><th>Signal</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="h in store.detail.holdings" :key="h.stock_id">
              <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: h.symbol } }">{{ h.symbol }}</RouterLink></td>
              <td>{{ h.quantity }}</td>
              <td>{{ formatPrice(h.avg_cost) }}</td>
              <td>{{ formatPrice(h.invested) }}</td>
              <td>{{ h.current_price !== null ? formatPrice(h.current_price) : '—' }}</td>
              <td>{{ h.current_value !== null ? formatPrice(h.current_value) : '—' }}</td>
              <td :class="changeTone(h.unrealized_pnl)">
                <span v-if="h.unrealized_pnl !== null">
                  Rs. {{ formatPrice(h.unrealized_pnl) }} ({{ h.unrealized_pnl_pct }}%)
                </span>
                <span v-else>—</span>
              </td>
              <td>
                <span v-if="h.latest_signal" class="badge" :class="h.latest_signal.signal">{{ formatSignal(h.latest_signal.signal) }}</span>
                <span v-else class="muted">No data</span>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">No open holdings — add a buy transaction to get started.</p>
      </div>
    </template>

    <div class="card" style="margin-top: 16px">
      <h3>Transaction History</h3>
      <p v-if="deleteError" class="error-text">{{ deleteError }}</p>
      <table class="table" v-if="store.transactions.length">
        <thead>
          <tr><th>Date</th><th>Symbol</th><th>Type</th><th>Qty</th><th>Price</th><th>Fees</th><th>Total</th><th>Notes</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="tx in store.transactions" :key="tx.id">
            <td>{{ tx.transaction_date }}</td>
            <td>
              <RouterLink v-if="tx.stock" :to="{ name: 'stock-detail', params: { symbol: tx.stock.symbol } }">{{ tx.stock.symbol }}</RouterLink>
            </td>
            <td><span class="badge" :class="tx.type">{{ tx.type }}</span></td>
            <td>{{ tx.quantity }}</td>
            <td>{{ formatPrice(tx.price) }}</td>
            <td>{{ formatPrice(tx.fees) }}</td>
            <td>{{ formatPrice(tx.quantity * tx.price + (tx.type === 'buy' ? Number(tx.fees) : -Number(tx.fees))) }}</td>
            <td class="muted">{{ tx.notes || '—' }}</td>
            <td>
              <button class="btn-secondary btn" :disabled="deletingId === tx.id" @click="handleDelete(tx)">
                {{ deletingId === tx.id ? 'Deleting…' : 'Delete' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">No transactions yet.</p>
    </div>
  </div>
</template>

<style scoped>
.actions {
  display: flex;
  gap: 10px;
  align-items: center;
}

.type-toggle {
  display: flex;
  gap: 8px;
}

.type-toggle .active {
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
