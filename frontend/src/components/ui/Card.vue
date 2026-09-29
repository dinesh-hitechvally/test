<!--
  A white panel (.card). Optional <h3> title — plain text via `title`, or
  richer content (badges, counts) via the #title slot. class/style/v-if on
  <Card> pass straight through to the panel.

  <Card title="Holdings" style="margin-top: 16px"> …table… </Card>
  <Card><template #title>Flagged <span class="badge sell">3</span></template> … </Card>
-->
<script setup>
defineProps({
  title: { type: String, default: '' },
})
</script>

<template>
  <div class="card">
    <h3 v-if="title || $slots.title"><slot name="title">{{ title }}</slot></h3>
    <slot />
  </div>
</template>
