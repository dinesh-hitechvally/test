<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { downloadFile } from '../api/client'
import { usePortfolioStore } from '../stores/portfolio'

const store = usePortfolioStore()
const exportError = ref('')

// A plain <a href> can't carry the Authorization header the API needs, so
// this fetches the file (with it) and saves it via downloadFile() instead.
async function exportAs(format) {
  exportError.value = ''
  try {
    await downloadFile(`/portfolios/${store.activePortfolioId}/export${format ? `-${format}` : ''}`)
  } catch {
    exportError.value = 'Export failed.'
  }
}

onMounted(async () => {
  if (store.portfolios.length === 0) await store.fetchPortfolios()
})
</script>

<template>
  <div>
    <LoadingState v-if="!store.activePortfolioId">Loading your portfolio…</LoadingState>

    <template v-else>
      <p v-if="exportError" class="error-text">{{ exportError }}</p>

      <Card class="export-options">
        <a href="#" class="export-card" @click.prevent="exportAs('pdf')">
          <strong>PDF Statement</strong>
          <span class="muted">Summary, holdings, and realized gains/losses — formatted for printing or sharing.</span>
        </a>
        <a href="#" class="export-card" @click.prevent="exportAs('excel')">
          <strong>Excel Workbook</strong>
          <span class="muted">Holdings and full transaction history as two worksheets — good for your own analysis.</span>
        </a>
        <a href="#" class="export-card" @click.prevent="exportAs('')">
          <strong>CSV</strong>
          <span class="muted">Plain-text holdings + transactions, one file — easiest to import elsewhere.</span>
        </a>
      </Card>
    </template>
  </div>
</template>

<style scoped>
.export-options {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 14px;
}

.export-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 16px;
  border: 1px solid var(--border);
  border-radius: 10px;
  text-decoration: none;
  color: var(--text);
}

.export-card:hover {
  border-color: #2563eb;
  background: #f8faff;
}
</style>
