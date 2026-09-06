<script setup>
import { computed, toRef } from 'vue'
import { RouterLink } from 'vue-router'
import { formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const props = defineProps({
  stocks: { type: Array, required: true },
})

defineEmits(['set-alert', 'remove'])

const { sorted, toggleSort, sortIndicator } = useSortableTable(toRef(props, 'stocks'), {
  valueGetters: {
    close: (s) => (s.latest_price?.close_price !== undefined ? Number(s.latest_price.close_price) : null),
    signal: (s) => s.latest_signal?.signal ?? null,
  },
})

function formatSignal(label) {
  return label.replace('_', ' ')
}

function changeTone(pct) {
  if (pct === null || pct === undefined) return ''
  return pct > 0 ? 'positive' : pct < 0 ? 'negative' : ''
}
</script>

<template>
  <table class="table">
    <thead>
      <tr>
        <th class="sortable" @click="toggleSort('symbol')">Symbol {{ sortIndicator('symbol') }}</th>
        <th class="sortable" @click="toggleSort('company_name')">Company {{ sortIndicator('company_name') }}</th>
        <th class="sortable" @click="toggleSort('sector')">Sector {{ sortIndicator('sector') }}</th>
        <th class="sortable" @click="toggleSort('close')">Last Close {{ sortIndicator('close') }}</th>
        <th class="sortable" @click="toggleSort('change_pct')">% Change {{ sortIndicator('change_pct') }}</th>
        <th class="sortable" @click="toggleSort('signal')">Signal {{ sortIndicator('signal') }}</th>
        <th>Price Alert</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="stock in sorted" :key="stock.id">
        <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: stock.symbol } }">{{ stock.symbol }}</RouterLink></td>
        <td>{{ stock.company_name || '—' }}</td>
        <td>{{ stock.sector || 'Other' }}</td>
        <td>{{ formatPrice(stock.latest_price?.close_price) }}</td>
        <td :class="changeTone(stock.change_pct)">
          {{ stock.change_pct !== null && stock.change_pct !== undefined ? `${stock.change_pct > 0 ? '+' : ''}${stock.change_pct}%` : '—' }}
        </td>
        <td>
          <span v-if="stock.latest_signal" class="badge" :class="stock.latest_signal.signal">
            {{ formatSignal(stock.latest_signal.signal) }}
          </span>
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
