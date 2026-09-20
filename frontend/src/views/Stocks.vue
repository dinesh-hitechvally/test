<script setup>
import { onMounted, ref } from 'vue'
import { useStocksStore } from '../stores/stocks'
import MarketTable from '../components/MarketTable.vue'

const store = useStocksStore()

const showAddForm = ref(false)
const newSymbol = ref('')
const newCompanyName = ref('')
const newSector = ref('')
const addError = ref('')

const showImportForm = ref(false)
const importFile = ref(null)
const importSymbol = ref('')
const importResult = ref('')
const importError = ref('')
const importing = ref(false)

async function handleAddStock() {
  addError.value = ''
  try {
    await store.addStock({
      symbol: newSymbol.value,
      company_name: newCompanyName.value || null,
      sector: newSector.value || null,
    })
    newSymbol.value = ''
    newCompanyName.value = ''
    newSector.value = ''
    showAddForm.value = false
  } catch (e) {
    addError.value = e.response?.data?.message || 'Could not add stock.'
  }
}

function handleFileChange(e) {
  importFile.value = e.target.files[0] || null
}

async function handleImport() {
  if (!importFile.value) return
  importing.value = true
  importError.value = ''
  importResult.value = ''
  try {
    const result = await store.importCsv(importFile.value, importSymbol.value || null)
    importResult.value = `Imported ${result.prices} price rows (${result.stocks} new stock(s)).`
    await store.fetchStocks()
  } catch (e) {
    importError.value = e.response?.data?.message || 'Import failed.'
  } finally {
    importing.value = false
  }
}

onMounted(() => {
  if (store.stocks.length === 0) store.fetchStocks()
})
</script>

<template>
  <div>
    <div class="page-header">
      <h1>Stocks</h1>
      <div class="actions">
        <button class="btn-secondary btn" @click="showImportForm = !showImportForm">Import CSV</button>
        <button class="btn" @click="showAddForm = !showAddForm">Add Stock</button>
      </div>
    </div>

    <div v-if="showAddForm" class="card form-stack" style="margin-bottom: 20px">
      <h3>Add a new stock</h3>
      <input v-model="newSymbol" class="input" placeholder="Symbol (e.g. NABIL)" required />
      <input v-model="newCompanyName" class="input" placeholder="Company name (optional)" />
      <input v-model="newSector" class="input" placeholder="Sector (optional)" />
      <p v-if="addError" class="error-text">{{ addError }}</p>
      <button class="btn" @click="handleAddStock">Save</button>
    </div>

    <div v-if="showImportForm" class="card form-stack" style="margin-bottom: 20px">
      <h3>Import historical prices from CSV</h3>
      <p class="muted">Columns: Date, Open, High, Low, Close, Volume. A Symbol column is used per-row if present, otherwise provide one below.</p>
      <input type="file" accept=".csv,text/csv" @change="handleFileChange" />
      <input v-model="importSymbol" class="input" placeholder="Symbol (only if CSV has no Symbol column)" />
      <p v-if="importError" class="error-text">{{ importError }}</p>
      <p v-if="importResult" class="muted">{{ importResult }}</p>
      <button class="btn" :disabled="importing" @click="handleImport">{{ importing ? 'Importing…' : 'Import' }}</button>
    </div>

    <MarketTable :stocks="store.stocks" :show-turnover-volume="true" :show-ai-opinion="true" />
  </div>
</template>

<style scoped>
.actions {
  display: flex;
  gap: 10px;
}
</style>
