<!--
  Tells the user when the API is not working (down, timed out, server error) without breaking the page: the
  rest of the screen keeps working and shows whatever data it already has. Fed by utils/apiActivity.js.
-->
<script setup>
import { dismissNotice, notices } from '../../utils/apiActivity'
</script>

<template>
  <div v-if="notices.length" class="api-notices" role="alert">
    <div v-for="n in notices" :key="n.id" class="api-notice">
      <span><strong>Some data could not be loaded.</strong> {{ n.message }} The rest of the page still works.</span>
      <button type="button" class="dismiss" aria-label="Dismiss" @click="dismissNotice(n.id)">×</button>
    </div>
  </div>
</template>

<style scoped>
.api-notices {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 16px;
}

.api-notice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 14px;
  border: 1px solid #fcd34d;
  border-left: 4px solid #d97706;
  border-radius: var(--radius-sm);
  background: #fffbeb;
  color: #78350f;
  font-size: 0.88rem;
}

.dismiss {
  border: 0;
  background: none;
  color: inherit;
  font-size: 1.2rem;
  line-height: 1;
  cursor: pointer;
}
</style>
