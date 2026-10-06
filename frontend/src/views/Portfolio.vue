<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import * as reportsApi from '../api/reports'
import { usePortfolioStore } from '../stores/portfolio'
import { useStocksStore } from '../stores/stocks'
import { changeTone, formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const store = usePortfolioStore()
const stocksStore = useStocksStore()

const portfolioOptions = computed(() => store.portfolios.map((p) => ({ value: p.id, label: p.name })))
const stockOptions = computed(() => stocksStore.stocks.map((s) => ({ value: s.id, label: `${s.symbol} — ${s.company_name}` })))

const decisions = computed(() => Object.fromEntries(store.sellChecks.map((d) => [d.stock_id, d])))

const holdingsTable = useSortableTable(
  computed(() => store.detail?.holdings ?? []),
  {
    valueGetters: {
      signal: (h) => h.latest_signal?.signal ?? null,
      sell_check: (h) => decisions.value[h.stock_id]?.action ?? null,
    },
  }
)
const { sorted: sortedHoldings } = holdingsTable

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
  await store.refreshAll(store.activePortfolioId)
}

const editingCash = ref(false)
const cashInput = ref('')
const cashError = ref('')

function openCashEditor() {
  cashInput.value = store.detail?.portfolio?.cash_balance ?? 0
  cashError.value = ''
  editingCash.value = true
}

async function saveCash() {
  cashError.value = ''
  try {
    await store.setCash(store.activePortfolioId, cashInput.value)
    editingCash.value = false
  } catch (e) {
    cashError.value = e.response?.data?.errors?.cash_balance?.[0] || e.response?.data?.message || 'Could not save cash.'
  }
}

const cancellingOrderId = ref(null)

async function cancelOrder(order) {
  if (!window.confirm(`Cancel the pending buy order for ${order.quantity} ${order.stock?.symbol ?? ''}?`)) return
  cancellingOrderId.value = order.id
  try {
    await store.cancelBuyOrder(store.activePortfolioId, order.id)
  } finally {
    cancellingOrderId.value = null
  }
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

const editingTargetFor = ref(null) // the holding object currently being edited, or null
const targetStopLoss = ref('')
const targetPrice = ref('')
const targetNotes = ref('')
const targetError = ref('')
const targetSaving = ref(false)
const suggesting = ref(false)
const suggestionNote = ref('')

function openTargetEditor(holding) {
  editingTargetFor.value = holding
  targetStopLoss.value = holding.stop_loss ?? ''
  targetPrice.value = holding.target_price ?? ''
  targetNotes.value = holding.target_notes || ''
  targetError.value = ''
  suggestionNote.value = ''
}

function closeTargetEditor() {
  editingTargetFor.value = null
}

async function suggestLevels() {
  if (!editingTargetFor.value) return
  suggesting.value = true
  suggestionNote.value = ''
  try {
    const data = await reportsApi.technical(editingTargetFor.value.symbol)
    const sr = data.report?.support_resistance
    // Deliberately from support/resistance, not trade_setup — trade_setup is
    // framed for opening a fresh position in whatever direction the trend
    // favors (so a bearish stock gets a stop *above* price), which is
    // backwards for a holding you already own long. Support is always below
    // price and resistance always above, so they're the correctly-oriented
    // pair for "where do I protect / take profit on what I hold."
    const support = sr?.support?.[0]?.price ?? null
    const resistance = sr?.resistance?.[0]?.price ?? null

    if (support === null && resistance === null) {
      suggestionNote.value = 'Not enough data yet for a suggested setup on this stock.'
      return
    }
    if (support !== null) targetStopLoss.value = support
    if (resistance !== null) targetPrice.value = resistance
    suggestionNote.value = 'From the nearest support (stop-loss) / resistance (target) levels in the Technical Analysis report — review before saving.'
  } catch {
    suggestionNote.value = 'Could not fetch a suggestion right now.'
  } finally {
    suggesting.value = false
  }
}

async function saveTarget() {
  if (!editingTargetFor.value) return
  targetError.value = ''
  targetSaving.value = true
  try {
    await store.setPositionTarget(store.activePortfolioId, editingTargetFor.value.stock_id, {
      stop_loss: targetStopLoss.value || null,
      target_price: targetPrice.value || null,
      notes: targetNotes.value || null,
    })
    closeTargetEditor()
  } catch (e) {
    targetError.value = e.response?.data?.message || 'Could not save levels.'
  } finally {
    targetSaving.value = false
  }
}

async function clearTarget() {
  targetStopLoss.value = ''
  targetPrice.value = ''
  await saveTarget()
}

function stopDistanceLabel(pct) {
  if (pct === null) return null
  return pct >= 0 ? `${pct}% above stop` : `${Math.abs(pct)}% below stop`
}

function targetDistanceLabel(pct) {
  if (pct === null) return null
  return pct >= 0 ? `${pct}% to target` : `${Math.abs(pct)}% past target`
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
        <SearchableSelect
          v-if="store.portfolios.length > 1"
          v-model="store.activePortfolioId"
          :options="portfolioOptions"
          style="max-width: 220px"
        />
        <button class="btn-secondary btn" @click="showNewPortfolioForm = !showNewPortfolioForm">New Portfolio</button>
        <button class="btn" :disabled="!store.activePortfolioId" @click="showAddForm = !showAddForm">Add Transaction</button>
      </div>
    </div>

    <LoadingState v-if="!store.activePortfolioId">Loading your portfolio…</LoadingState>

    <div v-if="showNewPortfolioForm" class="card form-stack" style="margin-bottom: 20px; flex-direction: row; align-items: center; max-width: none">
      <input v-model="newPortfolioName" class="input" placeholder="Portfolio name" style="max-width: 260px" />
      <button class="btn" @click="handleCreatePortfolio">Create</button>
    </div>

    <Card v-if="showAddForm" title="Add a transaction" class="form-stack" style="margin-bottom: 20px">
      <div class="type-toggle">
        <button class="btn-secondary btn" :class="{ active: txType === 'buy' }" @click="txType = 'buy'">Buy</button>
        <button class="btn-secondary btn" :class="{ active: txType === 'sell' }" @click="txType = 'sell'">Sell</button>
      </div>
      <SearchableSelect v-model="txStockId" :options="stockOptions" placeholder="Select a stock…" />
      <input v-model="txQuantity" type="number" min="1" class="input" placeholder="Quantity" />
      <input v-model="txPrice" type="number" min="0.01" step="0.01" class="input" placeholder="Price per share (Rs.)" />
      <input v-model="txFees" type="number" min="0" step="0.01" class="input" placeholder="Fees / brokerage (optional)" />
      <input v-model="txDate" type="date" class="input" />
      <input v-model="txNotes" class="input" placeholder="Notes (optional)" />
      <p v-if="txError" class="error-text">{{ txError }}</p>
      <button class="btn" :disabled="submitting" @click="handleAddTransaction">{{ submitting ? 'Saving…' : 'Save' }}</button>
    </Card>

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

      <div class="grid grid-cards" style="margin-top: 16px">
        <Card title="Cash">
          <template v-if="!editingCash">
            <p class="cash">Rs. {{ formatPrice(store.detail.portfolio.cash_balance) }}</p>
            <p class="muted small">
              Available for new buys. Set it by hand — recording a transaction doesn't change it. Pending buy orders reserve part of it.
            </p>
            <button class="btn-secondary btn" @click="openCashEditor">Edit cash</button>
          </template>
          <div v-else class="form-stack">
            <input v-model="cashInput" type="number" min="0" step="0.01" class="input" placeholder="Cash (Rs.)" />
            <p v-if="cashError" class="error-text">{{ cashError }}</p>
            <div class="picker-row">
              <button class="btn" @click="saveCash">Save</button>
              <button class="btn-secondary btn" @click="editingCash = false">Cancel</button>
            </div>
          </div>
        </Card>
      </div>

      <Card v-if="store.buyOrders.length" title="Pending Buy Orders" style="margin-top: 16px">
        <p class="muted small">
          Planned buys that passed the risk, portfolio and cash checks. Record the purchase as a transaction (Add Transaction) and the
          order is marked filled and its stop-loss / target are set on the holding. Unfilled orders lapse after 5 days.
        </p>
        <table v-align-numbers class="table">
          <thead>
            <tr><th>Symbol</th><th>Qty</th><th>Entry</th><th>Stop-Loss</th><th>Target</th><th>R:R</th><th>Cost</th><th>Max loss</th><th>Planned</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="o in store.buyOrders" :key="o.id">
              <td><StockLink v-if="o.stock" :symbol="o.stock.symbol" /></td>
              <td>{{ o.quantity }}</td>
              <td>{{ formatPrice(o.entry_price) }}</td>
              <td class="negative">{{ formatPrice(o.stop_loss) }}</td>
              <td class="positive">{{ formatPrice(o.target_price) }}</td>
              <td>1:{{ o.risk_reward }}</td>
              <td>{{ formatPrice(Number(o.position_value) + Number(o.fees)) }}</td>
              <td>{{ formatPrice(o.risk_amount) }}</td>
              <td class="muted">{{ o.trade_date }}</td>
              <td>
                <button class="btn-secondary btn" :disabled="cancellingOrderId === o.id" @click="cancelOrder(o)">Cancel</button>
              </td>
            </tr>
          </tbody>
        </table>
      </Card>

      <Card title="Holdings" style="margin-top: 16px">
        <table v-align-numbers class="table" v-if="store.detail.holdings.length">
          <thead>
            <tr>
              <SortableTh :table="holdingsTable" column="symbol">Symbol</SortableTh>
              <SortableTh :table="holdingsTable" column="quantity">Qty</SortableTh>
              <SortableTh :table="holdingsTable" column="avg_cost">Avg Cost</SortableTh>
              <SortableTh :table="holdingsTable" column="invested">Invested</SortableTh>
              <SortableTh :table="holdingsTable" column="current_price">Current Price</SortableTh>
              <SortableTh :table="holdingsTable" column="current_value">Current Value</SortableTh>
              <SortableTh :table="holdingsTable" column="unrealized_pnl">Unrealized P&L</SortableTh>
              <SortableTh :table="holdingsTable" column="signal">Signal</SortableTh>
              <th>Stop-Loss / Target</th>
              <SortableTh :table="holdingsTable" column="sell_check">Sell check</SortableTh>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="h in sortedHoldings" :key="h.stock_id">
              <td><StockLink :symbol="h.symbol" /></td>
              <td>
                {{ h.quantity }}
                <span v-if="h.bonus_shares_received > 0" class="muted small" :title="`Includes ${h.bonus_shares_received} bonus share(s) credited over time`">
                  (+{{ h.bonus_shares_received }})
                </span>
              </td>
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
                <SignalBadge v-if="h.latest_signal" :signal="h.latest_signal.signal" />
                <span v-else class="muted">No data</span>
              </td>
              <td>
                <template v-if="h.stop_loss !== null || h.target_price !== null || decisions[h.stock_id]?.trailing_stop">
                  <div class="muted small">
                    <span v-if="h.stop_loss !== null">SL: {{ formatPrice(h.stop_loss) }}</span>
                    <span v-if="h.stop_loss !== null && h.target_price !== null"> · </span>
                    <span v-if="h.target_price !== null">TGT: {{ formatPrice(h.target_price) }}</span>
                  </div>
                  <div v-if="decisions[h.stock_id]?.trailing_stop" class="muted small" title="Follows the highest close since you bought, never moves down">
                    Trailing stop: {{ formatPrice(decisions[h.stock_id].trailing_stop) }}
                  </div>
                  <div class="muted small" v-if="h.pct_to_stop !== null || h.pct_to_target !== null">
                    <span v-if="h.pct_to_stop !== null">{{ stopDistanceLabel(h.pct_to_stop) }}</span>
                    <span v-if="h.pct_to_stop !== null && h.pct_to_target !== null"> · </span>
                    <span v-if="h.pct_to_target !== null">{{ targetDistanceLabel(h.pct_to_target) }}</span>
                  </div>
                </template>
                <span v-else class="muted">Not set</span>
              </td>
              <td>
                <SellBadge :decision="decisions[h.stock_id]" />
              </td>
              <td>
                <button class="btn-secondary btn" @click="openTargetEditor(h)">
                  {{ h.stop_loss !== null || h.target_price !== null ? 'Edit Levels' : 'Set Levels' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <EmptyState v-else>No open holdings — add a buy transaction to get started.</EmptyState>
      </Card>

      <Card v-if="editingTargetFor" :title="`Stop-Loss / Target — ${editingTargetFor.symbol}`" class="form-stack" style="margin-top: 16px">
        <p class="muted">
          Current price Rs. {{ formatPrice(editingTargetFor.current_price) }}, avg cost Rs. {{ formatPrice(editingTargetFor.avg_cost) }}.
          Set the levels where you'd exit this position — the Holdings table will flag it the moment price crosses either one.
        </p>
        <button class="btn-secondary btn" style="align-self: flex-start" :disabled="suggesting" @click="suggestLevels">
          {{ suggesting ? 'Fetching…' : 'Suggest levels from Technical Analysis' }}
        </button>
        <p v-if="suggestionNote" class="muted small">{{ suggestionNote }}</p>
        <input v-model="targetStopLoss" type="number" min="0" step="0.01" class="input" placeholder="Stop-loss price (Rs.)" />
        <input v-model="targetPrice" type="number" min="0" step="0.01" class="input" placeholder="Target price (Rs.)" />
        <input v-model="targetNotes" class="input" placeholder="Notes (optional)" />
        <p v-if="targetError" class="error-text">{{ targetError }}</p>
        <div class="picker-row">
          <button class="btn" :disabled="targetSaving" @click="saveTarget">{{ targetSaving ? 'Saving…' : 'Save' }}</button>
          <button class="btn-secondary btn" :disabled="targetSaving" @click="clearTarget">Clear Levels</button>
          <button class="btn-secondary btn" :disabled="targetSaving" @click="closeTargetEditor">Cancel</button>
        </div>
      </Card>
    </template>

    <Card title="Transaction History" style="margin-top: 16px">
      <p v-if="deleteError" class="error-text">{{ deleteError }}</p>
      <table v-align-numbers class="table" v-if="store.transactions.length">
        <thead>
          <tr><th>Date</th><th>Symbol</th><th>Type</th><th>Qty</th><th>Price</th><th>Fees</th><th>Total</th><th>Notes</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="tx in store.transactions" :key="tx.id">
            <td>{{ tx.transaction_date }}</td>
            <td>
              <StockLink v-if="tx.stock" :symbol="tx.stock.symbol" />
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
      <EmptyState v-else>No transactions yet.</EmptyState>
    </Card>
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

.small {
  font-size: 0.75rem;
}

.picker-row {
  display: flex;
  gap: 10px;
}

.cash {
  font-size: 1.4rem;
  font-weight: 700;
  margin: 0 0 6px;
}
</style>
