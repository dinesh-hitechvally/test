<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import * as stocksApi from '../api/stocks'
import { useColumnOptions } from '../composables/useColumnOptions'
import MarketTable from '../components/stock/MarketTable.vue'

// Screen Options: which columns this page shows. Saved in the browser, and the stock query asks
// the API only for the fields these columns need — switching one off stops it being fetched.
const columns = useColumnOptions('stocks', [
  { key: 'symbol', label: 'Symbol', locked: true },
  { key: 'company_name', label: 'Company' },
  { key: 'sector', label: 'Sector' },
  { key: 'last_close', label: 'Last Close' },
  { key: 'high', label: 'High' },
  { key: 'low', label: 'Low' },
  { key: 'high_52w', label: '52W High' },
  { key: 'low_52w', label: '52W Low' },
  { key: 'change_pct', label: '% Change' },
  { key: 'turnover', label: 'Turnover' },
  { key: 'volume', label: 'Volume' },
  { key: 'signal', label: 'Signal' },
  { key: 'ai_opinion', label: 'AI Opinion' },
])

// Kept here, not in the shared stocks store: this list holds only the columns shown, while other
// pages (search box, portfolio, watchlists…) rely on the store having the full set of fields.
const rows = ref([])
const loading = ref(true)
const error = ref('')

let latest = 0
async function load() {
  const request = ++latest
  loading.value = true
  error.value = ''
  try {
    const data = await stocksApi.list(null, columns.visibleKeys.value)
    if (request === latest) rows.value = data // ignore an older response that arrives late
  } catch (e) {
    if (request === latest) error.value = e.response?.data?.message || 'Could not load stocks.'
  } finally {
    if (request === latest) loading.value = false
  }
}

// Re-ask the API when the columns change (a short delay lets several ticks in a row become one request).
let timer = null
watch(columns.visibleKeys, () => {
  clearTimeout(timer)
  timer = setTimeout(load, 250)
})

onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div>
    <ScreenOptions
      title="Columns"
      :options="columns.options"
      :visible="columns.visibleKeys.value"
      note="Only the data for the columns you leave on is requested from the server. Your choice is saved in this browser."
      @toggle="columns.toggle"
      @reset="columns.reset"
    />

    <div class="page-header">
      <h1>Stocks</h1>
    </div>

    <p v-if="error" class="error-text">{{ error }}</p>
    <LoadingState v-if="loading && rows.length === 0" />
    <div v-else :class="{ updating: loading }">
      <MarketTable
        :stocks="rows"
        :columns="columns.visibleKeys.value"
        :show-turnover-volume="true"
        :show-ai-opinion="true"
      />
    </div>
  </div>
</template>

<style scoped>
.updating {
  opacity: 0.55;
  transition: opacity 0.15s;
}
</style>
