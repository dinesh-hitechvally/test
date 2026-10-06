<!--
  Wraps the page area. If a page itself crashes while drawing (usually because data it expected did not arrive),
  the sidebar and top bar stay, and this shows a short message with a Retry button instead of a blank screen.

  A failed API request is NOT a crash: pages keep showing, and ApiNotices says what did not load. So errors
  that are just failed requests are reported and swallowed here, and only genuine render errors show the fallback.
-->
<script setup>
import { onErrorCaptured, ref } from 'vue'
import { isApiOutage, reportApiError } from '../../utils/apiActivity'

const crashed = ref(false)
const attempt = ref(0)

onErrorCaptured((error) => {
  if (error?.response !== undefined || isApiOutage(error)) {
    reportApiError(error)

    return false // the page carries on without that data
  }

  console.error(error)
  crashed.value = true

  return false
})

function retry() {
  crashed.value = false
  attempt.value++ // remounts the page
}
</script>

<template>
  <div v-if="crashed" class="boundary card">
    <p><strong>This page could not be displayed.</strong></p>
    <p class="muted">Some data it needs may not have loaded. The menu and the rest of the app still work.</p>
    <button type="button" class="btn" @click="retry">Try again</button>
  </div>
  <slot v-else :key="attempt" />
</template>

<style scoped>
.boundary {
  max-width: 520px;
}
</style>
