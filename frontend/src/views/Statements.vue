<script setup>
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { apiBaseUrl } from '../api/client'
import { usePortfolioStore } from '../stores/portfolio'

const store = usePortfolioStore()

const csvUrl = computed(() => `${apiBaseUrl}/api/portfolios/${store.activePortfolioId}/export`)
const pdfUrl = computed(() => `${apiBaseUrl}/api/portfolios/${store.activePortfolioId}/export-pdf`)
const excelUrl = computed(() => `${apiBaseUrl}/api/portfolios/${store.activePortfolioId}/export-excel`)

onMounted(async () => {
  if (store.portfolios.length === 0) await store.fetchPortfolios()
})
</script>

<template>
  <div>
    <h1>Statements</h1>
    <p class="muted">
      Export your current portfolio — holdings, transactions, and realized gains/losses — as a document. For
      per-transaction realized-gain detail on screen, see
      <RouterLink :to="{ name: 'portfolio-reports' }">Realized P/L / Tax Report</RouterLink>.
    </p>

    <p v-if="!store.activePortfolioId" class="muted">Loading your portfolio…</p>

    <div v-else class="card export-options">
      <a class="export-card" :href="pdfUrl">
        <strong>PDF Statement</strong>
        <span class="muted">Summary, holdings, and realized gains/losses — formatted for printing or sharing.</span>
      </a>
      <a class="export-card" :href="excelUrl">
        <strong>Excel Workbook</strong>
        <span class="muted">Holdings and full transaction history as two worksheets — good for your own analysis.</span>
      </a>
      <a class="export-card" :href="csvUrl">
        <strong>CSV</strong>
        <span class="muted">Plain-text holdings + transactions, one file — easiest to import elsewhere.</span>
      </a>
    </div>
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
