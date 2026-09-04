<script setup>
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { usePortfolioStore } from '../stores/portfolio'
import { formatPrice } from '../utils/format'

const store = usePortfolioStore()

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
          <tr><th>Date</th><th>Symbol</th><th>Type</th><th>Qty</th><th>Price</th><th>Fees</th><th>Total</th><th>Notes</th></tr>
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
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">No transactions yet.</p>
    </div>
  </div>
</template>
