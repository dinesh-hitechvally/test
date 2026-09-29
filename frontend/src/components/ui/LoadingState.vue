<!--
  The standard "Loading…" line while a page or card fetches its data.

  <LoadingState v-if="loading" />
  <LoadingState v-if="loading">Loading holdings…</LoadingState>
-->
<template>
  <p class="muted"><slot>Loading…</slot></p>
</template>
