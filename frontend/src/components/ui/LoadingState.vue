<!--
  The standard "Loading…" line while a page or card fetches its data, with a spinning icon.

  <LoadingState v-if="loading" />
  <LoadingState v-if="loading">Loading holdings…</LoadingState>
-->
<template>
  <p class="muted loading-line" role="status" aria-live="polite"><span class="spinner" aria-hidden="true" /><slot>Loading…</slot></p>
</template>
