<!--
  A clickable table header that sorts by one column, showing ▲/▼ when active.
  `table` is what useSortableTable() returns.

  const holdings = useSortableTable(rows, { defaultKey: 'symbol' })
  <SortableTh :table="holdings" column="symbol">Symbol</SortableTh>
-->
<script setup>
defineProps({
  table: { type: Object, required: true },
  column: { type: String, required: true },
})
</script>

<template>
  <th class="sortable" @click="table.toggleSort(column)"><slot /> {{ table.sortIndicator(column) }}</th>
</template>
