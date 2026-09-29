<script setup>
import { computed, toRef } from 'vue'
import { changeTone, formatPrice } from '../../utils/format'
import { useSortableTable } from '../../composables/useSortableTable'

const props = defineProps({
  stocks: { type: Array, required: true },
})

defineEmits(['set-alert', 'remove'])

const table = useSortableTable(toRef(props, 'stocks'), {
  valueGetters: {
    close: (s) => (s.latest_price?.close_price !== undefined ? Number(s.latest_price.close_price) : null),
    signal: (s) => s.latest_signal?.signal ?? null,
  },
})
const { sorted } = table
</script>

<template>
  <table v-align-numbers class="table">
    <thead>
      <tr>
        <SortableTh :table="table" column="symbol">Symbol</SortableTh>
        <SortableTh :table="table" column="company_name">Company</SortableTh>
        <SortableTh :table="table" column="sector">Sector</SortableTh>
        <SortableTh :table="table" column="close">Last Close</SortableTh>
        <SortableTh :table="table" column="change_pct">% Change</SortableTh>
        <SortableTh :table="table" column="signal">Signal</SortableTh>
        <th>Price Alert</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="stock in sorted" :key="stock.id">
        <td><StockLink :symbol="stock.symbol" /></td>
        <td>{{ stock.company_name || '—' }}</td>
        <td>{{ stock.sector || 'Other' }}</td>
        <td>{{ formatPrice(stock.latest_price?.close_price) }}</td>
        <td :class="changeTone(stock.change_pct)">
          {{ stock.change_pct !== null && stock.change_pct !== undefined ? `${stock.change_pct > 0 ? '+' : ''}${stock.change_pct}%` : '—' }}
        </td>
        <td>
          <SignalBadge v-if="stock.latest_signal" :signal="stock.latest_signal.signal" />
          <span v-else class="muted">No data</span>
        </td>
        <td class="muted">
          <span v-if="stock.pivot?.alert_price">Alert when {{ stock.pivot.alert_direction }} Rs. {{ formatPrice(stock.pivot.alert_price) }}</span>
          <span v-else>Not set</span>
        </td>
        <td class="row-actions">
          <button class="btn-secondary btn" @click="$emit('set-alert', stock)">Set Alert</button>
          <button class="btn-secondary btn" @click="$emit('remove', stock.id)">Remove</button>
        </td>
      </tr>
    </tbody>
  </table>
</template>

<style scoped>
.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}

.row-actions {
  display: flex;
  gap: 8px;
}
</style>
