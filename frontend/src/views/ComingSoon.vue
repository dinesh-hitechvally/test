<script setup>
import { useRoute } from 'vue-router'

const route = useRoute()
</script>

<template>
  <div>
    <h1>{{ route.meta.title }}</h1>
    <div class="card coming-soon">
      <div class="badge hold">Coming soon</div>
      <p>{{ route.meta.description || "This section isn't built yet." }}</p>
      <p class="muted">It's on the map because it's part of the planned navigation — ask to have it built when you're ready to prioritize it.</p>
    </div>
  </div>
</template>

<style scoped>
.coming-soon {
  max-width: 560px;
  margin-top: 16px;
}

.coming-soon .badge {
  margin-bottom: 12px;
}
</style>
