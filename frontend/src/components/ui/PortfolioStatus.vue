<!--
  What a portfolio page shows INSTEAD of its content when there is none to show yet: a loading line while it
  waits, a message with Try again when the server could not be reached, or a prompt when the user simply has no
  portfolio. Pair it with usePortfolioLoad().

  <PortfolioStatus v-if="loading || failed || !store.detail" :loading="loading" :failed="failed" @retry="load" />
  <Card v-else> … the page … </Card>
-->
<script setup>
defineProps({
  loading: { type: Boolean, default: false },
  failed: { type: Boolean, default: false },
})
defineEmits(['retry'])
</script>

<template>
  <LoadingState v-if="loading">Loading your portfolio…</LoadingState>
  <Card v-else-if="failed">
    <EmptyState>Could not load your portfolio. The connection to the server may be down.</EmptyState>
    <button type="button" class="btn" @click="$emit('retry')">Try again</button>
  </Card>
  <EmptyState v-else class="card">No portfolio yet. Create one from the Portfolio page to see this.</EmptyState>
</template>
