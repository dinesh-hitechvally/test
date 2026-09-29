<!--
  A buy/hold/sell badge, coloured by signal. Renders nothing when there's no signal.

  <SignalBadge :signal="stock.latest_signal?.signal" />
-->
<script setup>
import { formatSignal } from '../../utils/format'

defineProps({
  signal: { type: String, default: null },
})
</script>

<template>
  <span v-if="signal" class="badge" :class="signal">{{ formatSignal(signal) }}</span>
</template>
