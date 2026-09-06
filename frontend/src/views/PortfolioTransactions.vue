<script setup>
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { usePortfolioStore } from '../stores/portfolio'
import { formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const store = usePortfolioStore()

function txTotal(tx) {
  return tx.quantity * tx.price + (tx.type === 'buy' ? Number(tx.fees) : -Number(tx.fees))
}

const { sorted, toggleSort, sortIndicator } = useSortableTable(
  computed(() => store.transactions),
  {
    defaultKey: 'transaction_date',
    defaultDir: 'desc',
    valueGetters: {
      symbol: (tx) => tx.stock?.symbol ?? null,
      total: txTotal,
    },
  }
)

onMounted(async () => {
  await store.fetchPortfolios()
  if (store.activePortfolioId) await store.fetchTransactions(store.activePortfolioId)
})
</script>

<template>
  <div>
    <h1>Transactions</h1>
    <p class="muted">
      Full buy/sell history log. To add or delete a transaction, use
      <RouterLink :to="{ name: 'portfolio' }">Portfolio Overview</RouterLink>.
    </p>

    <div class="card">
      <table class="table" v-if="store.transactions.length">
        <thead>
          <tr>
            <th class="sortable" @click="toggleSort('transaction_date')">Date {{ sortIndicator('transaction_date') }}</th>
            <th class="sortable" @click="toggleSort('symbol')">Symbol {{ sortIndicator('symbol') }}</th>
            <th class="sortable" @click="toggleSort('type')">Type {{ sortIndicator('type') }}</th>
            <th class="sortable" @click="toggleSort('quantity')">Qty {{ sortIndicator('quantity') }}</th>
            <th class="sortable" @click="toggleSort('price')">Price {{ sortIndicator('price') }}</th>
            <th class="sortable" @click="toggleSort('fees')">Fees {{ sortIndicator('fees') }}</th>
            <th class="sortable" @click="toggleSort('total')">Total {{ sortIndicator('total') }}</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="tx in sorted" :key="tx.id">
            <td>{{ tx.transaction_date }}</td>
            <td>
              <RouterLink v-if="tx.stock" :to="{ name: 'stock-detail', params: { symbol: tx.stock.symbol } }">{{ tx.stock.symbol }}</RouterLink>
            </td>
            <td><span class="badge" :class="tx.type">{{ tx.type }}</span></td>
            <td>{{ tx.quantity }}</td>
            <td>{{ formatPrice(tx.price) }}</td>
            <td>{{ formatPrice(tx.fees) }}</td>
            <td>{{ formatPrice(txTotal(tx)) }}</td>
            <td class="muted">{{ tx.notes || '—' }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">No transactions yet.</p>
    </div>
  </div>
</template>
