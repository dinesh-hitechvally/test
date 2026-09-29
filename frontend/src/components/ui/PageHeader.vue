<!--
  The title block at the top of a page: <h1>, plus an optional intro line.

  <PageHeader title="Buy Signals">Stocks whose latest signal is a buy…</PageHeader>
  <PageHeader><template #title>{{ stock.symbol }} <span class="muted">…</span></template></PageHeader>
-->
<script setup>
defineProps({
  title: { type: String, default: '' },
})
</script>

<template>
  <h1><slot name="title">{{ title }}</slot></h1>
  <p v-if="$slots.default" class="muted"><slot /></p>
</template>
