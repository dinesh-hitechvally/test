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

const txTable = useSortableTable(
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
const { sorted } = txTable

onMounted(async () => {
  await store.fetchPortfolios()
  if (store.activePortfolioId) await store.fetchTransactions(store.activePortfolioId)
})
</script>

<template>
  <div>
    <PageHeader title="Transactions">
      Full buy/sell history log. To add or delete a transaction, use
      <RouterLink :to="{ name: 'portfolio' }">Portfolio Overview</RouterLink>.
    </PageHeader>

    <Card>
      <table v-align-numbers class="table" v-if="store.transactions.length">
        <thead>
          <tr>
            <SortableTh :table="txTable" column="transaction_date">Date</SortableTh>
            <SortableTh :table="txTable" column="symbol">Symbol</SortableTh>
            <SortableTh :table="txTable" column="type">Type</SortableTh>
            <SortableTh :table="txTable" column="quantity">Qty</SortableTh>
            <SortableTh :table="txTable" column="price">Price</SortableTh>
            <SortableTh :table="txTable" column="fees">Fees</SortableTh>
            <SortableTh :table="txTable" column="total">Total</SortableTh>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="tx in sorted" :key="tx.id">
            <td>{{ tx.transaction_date }}</td>
            <td>
              <StockLink v-if="tx.stock" :symbol="tx.stock.symbol" />
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
      <EmptyState v-else>No transactions yet.</EmptyState>
    </Card>
  </div>
</template>
