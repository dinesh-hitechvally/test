<script setup>
import { computed, onMounted, ref } from 'vue'
import * as marketApi from '../api/market'
import { useStocksStore } from '../stores/stocks'
import { useSortableTable } from '../composables/useSortableTable'

const store = useStocksStore()
const flaggedStocks = ref([])
const secretConfigured = ref(true)
const loading = ref(true)

const logsTable = useSortableTable(
  computed(() => store.scrapeLogs),
  { defaultKey: 'created_at', defaultDir: 'desc' }
)
const { sorted: sortedLogs } = logsTable

const flaggedTable = useSortableTable(flaggedStocks, { defaultKey: 'scrape_error_at', defaultDir: 'desc' })
const { sorted: sortedFlagged } = flaggedTable

async function load() {
  loading.value = true
  const [status] = await Promise.all([marketApi.dataSourceStatus(), store.fetchScrapeLogs()])
  flaggedStocks.value = status.flagged_stocks
  secretConfigured.value = status.secret_configured
  loading.value = false
}

const sourceLabels = { history: 'Full History' }

onMounted(load)
</script>

<template>
  <div>
    <p class="muted">
      Data is fetched by the <code>/cron/*</code> URLs (see <code>routes/web.php</code>), scheduled in cPanel cron.
    </p>

    <p v-if="!loading && !secretConfigured" class="error-text card" style="margin-top: 12px">
      <code>CRON_SECRET</code> isn't set in the backend's <code>.env</code> — every cron URL will 403 until it is.
    </p>

    <Card v-if="!loading" style="margin-top: 12px">
      <template #title>Stocks with Data Issues <span v-if="sortedFlagged.length" class="badge sell">{{ sortedFlagged.length }}</span></template>
      <p class="muted">
        A stock lands here when its last full-history or dividend fetch failed — not a retry, just a flag so a
        failure doesn't sit silently in a log file. Cleared automatically the next time that same fetch succeeds (e.g.
        re-run <code>fetch/history/&lt;symbol&gt;</code> or <code>fetch/dividends/&lt;symbol&gt;</code>).
      </p>
      <table v-align-numbers class="table" v-if="sortedFlagged.length">
        <thead>
          <tr>
            <SortableTh :table="flaggedTable" column="symbol">Symbol</SortableTh>
            <SortableTh :table="flaggedTable" column="company_name">Company</SortableTh>
            <SortableTh :table="flaggedTable" column="scrape_error_source">Failed on</SortableTh>
            <th>Error</th>
            <SortableTh :table="flaggedTable" column="scrape_error_at">When</SortableTh>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in sortedFlagged" :key="s.id">
            <td><StockLink :symbol="s.symbol" /></td>
            <td class="muted">{{ s.company_name || '—' }}</td>
            <td><span class="badge sell">{{ sourceLabels[s.scrape_error_source] || s.scrape_error_source }}</span></td>
            <td class="muted">{{ s.scrape_error }}</td>
            <td class="muted">{{ new Date(s.scrape_error_at).toLocaleString() }}</td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-else>No flagged stocks right now — every full-history fetch that's been tried has succeeded.</EmptyState>
    </Card>

    <Card title="Recent Scrape Activity" style="margin-top: 16px">
      <LoadingState v-if="loading" />
      <table v-align-numbers class="table" v-else-if="store.scrapeLogs.length">
        <thead>
          <tr>
            <SortableTh :table="logsTable" column="source">Source</SortableTh>
            <SortableTh :table="logsTable" column="status">Status</SortableTh>
            <SortableTh :table="logsTable" column="records_processed">Records</SortableTh>
            <th>Message</th>
            <SortableTh :table="logsTable" column="created_at">When</SortableTh>
          </tr>
        </thead>
        <tbody>
          <tr v-for="log in sortedLogs" :key="log.id">
            <td>{{ log.source }}</td>
            <td><span class="badge" :class="log.status === 'success' ? 'buy' : 'sell'">{{ log.status }}</span></td>
            <td>{{ log.records_processed }}</td>
            <td class="muted">{{ log.message }}</td>
            <td class="muted">{{ new Date(log.created_at).toLocaleString() }}</td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-else>No scrape activity logged yet.</EmptyState>
    </Card>
  </div>
</template>

<style scoped>
code {
  background: #f1f5f9;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 0.82rem;
}
</style>
