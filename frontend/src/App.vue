<script setup>
import { computed, watch } from 'vue'
import { useAuthStore } from './stores/auth'
import { useStocksStore } from './stores/stocks'
import AppSidebar from './components/AppSidebar.vue'
import AppTopbar from './components/AppTopbar.vue'

const auth = useAuthStore()
const stocksStore = useStocksStore()
const isAuthenticated = computed(() => auth.isAuthenticated)

watch(
  isAuthenticated,
  (authed) => {
    if (authed) {
      stocksStore.fetchStocks()
      stocksStore.fetchScrapeLogs()
    }
  },
  { immediate: true }
)
</script>

<template>
  <div v-if="isAuthenticated" class="app-shell">
    <AppSidebar />
    <div class="main-column">
      <AppTopbar />
      <main class="content">
        <RouterView />
      </main>
    </div>
  </div>
  <RouterView v-else />
</template>

<style scoped>
.app-shell {
  min-height: 100vh;
  display: flex;
}

.main-column {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.content {
  flex: 1;
  padding: 24px;
  width: 100%;
  box-sizing: border-box;
}
</style>
