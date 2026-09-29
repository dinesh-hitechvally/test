<!--
  A stock symbol linking to its Stock Detail page. The link text defaults to
  the symbol; pass a slot to show something else.

  <StockLink :symbol="s.symbol" />
-->
<script setup>
defineProps({
  symbol: { type: String, required: true },
})
</script>

<template>
  <RouterLink :to="{ name: 'stock-detail', params: { symbol } }"><slot>{{ symbol }}</slot></RouterLink>
</template>
