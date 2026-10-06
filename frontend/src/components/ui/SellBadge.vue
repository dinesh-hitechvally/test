<!--
  The sell/hold verdict for one holding (a row of the sellChecks query): a red
  "Sell — <main reason>" badge with every reason that fired listed on hover and
  below it, or a grey "Hold". Renders nothing without a decision.

  <SellBadge :decision="decisionFor(h.stock_id)" />
-->
<script setup>
import { formatSellReason } from '../../utils/format'

defineProps({
  decision: { type: Object, default: null },
})
</script>

<template>
  <div v-if="decision">
    <span v-if="decision.action === 'sell'" class="badge sell" :title="decision.reasons.map((r) => r.detail).join('\n')">
      Sell — {{ formatSellReason(decision.primary_reason) }}
    </span>
    <span v-else class="badge hold" :title="decision.note || 'No sell rule has triggered'">Hold</span>
    <ul v-if="decision.action === 'sell' && decision.reasons.length > 1" class="more">
      <li v-for="r in decision.reasons.slice(1)" :key="r.rule">{{ formatSellReason(r.rule) }}</li>
    </ul>
  </div>
</template>

<style scoped>
.more {
  margin: 4px 0 0;
  padding-left: 16px;
  font-size: 0.72rem;
  color: var(--text-muted);
}
</style>
