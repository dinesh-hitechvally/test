<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { useStocksStore } from '../stores/stocks'
import { useSortableTable } from '../composables/useSortableTable'

const store = useStocksStore()
const flaggedStocks = ref([])
const secretConfigured = ref(true)
const loading = ref(true)

const { sorted: sortedLogs, toggleSort, sortIndicator } = useSortableTable(
  computed(() => store.scrapeLogs),
  { defaultKey: 'created_at', defaultDir: 'desc' }
)

const {
  sorted: sortedFlagged,
  toggleSort: toggleFlaggedSort,
  sortIndicator: flaggedSortIndicator,
} = useSortableTable(flaggedStocks, { defaultKey: 'scrape_error_at', defaultDir: 'desc' })

async function load() {
  loading.value = true
  const [scheduleRes] = await Promise.all([client.get('/schedule'), store.fetchScrapeLogs()])
  flaggedStocks.value = scheduleRes.data.flagged_stocks
  secretConfigured.value = scheduleRes.data.secret_configured
  loading.value = false
}

const sourceLabels = { history: 'Full History', sector: 'Sector' }

onMounted(load)
</script>

<template>
  <div>
    <h1>Data Source / Scrape Settings</h1>
    <p class="muted">
      Data is fetched by the <code>/cron/*</code> URLs (see <code>routes/web.php</code>), scheduled in cPanel cron.
    </p>

    <p v-if="!loading && !secretConfigured" class="error-text card" style="margin-top: 12px">
      <code>CRON_SECRET</code> isn't set in the backend's <code>.env</code> — every cron URL will 403 until it is.
    </p>

    <div v-if="!loading" class="card" style="margin-top: 12px">
      <h3>Stocks with Data Issues <span v-if="sortedFlagged.length" class="badge sell">{{ sortedFlagged.length }}</span></h3>
      <p class="muted">
        A stock lands here when its last full-history, sector, or dividend fetch failed — not a retry, just a flag so a
        failure doesn't sit silently in a log file. Cleared automatically the next time that same fetch succeeds (e.g.
        re-run <code>scrape/fetch-history/&lt;symbol&gt;</code>, <code>scrape/sync-sectors</code>, or
        <code>scrape/sync-dividends</code>).
      </p>
      <table v-align-numbers class="table" v-if="sortedFlagged.length">
        <thead>
          <tr>
            <th class="sortable" @click="toggleFlaggedSort('symbol')">Symbol {{ flaggedSortIndicator('symbol') }}</th>
            <th class="sortable" @click="toggleFlaggedSort('company_name')">Company {{ flaggedSortIndicator('company_name') }}</th>
            <th class="sortable" @click="toggleFlaggedSort('scrape_error_source')">Failed on {{ flaggedSortIndicator('scrape_error_source') }}</th>
            <th>Error</th>
            <th class="sortable" @click="toggleFlaggedSort('scrape_error_at')">When {{ flaggedSortIndicator('scrape_error_at') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in sortedFlagged" :key="s.id">
            <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
            <td class="muted">{{ s.company_name || '—' }}</td>
            <td><span class="badge sell">{{ sourceLabels[s.scrape_error_source] || s.scrape_error_source }}</span></td>
            <td class="muted">{{ s.scrape_error }}</td>
            <td class="muted">{{ new Date(s.scrape_error_at).toLocaleString() }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">No flagged stocks right now — every full-history and sector fetch that's been tried has succeeded.</p>
    </div>

    <div class="card" style="margin-top: 16px">
      <h3>Recent Scrape Activity</h3>
      <p v-if="loading" class="muted">Loading…</p>
      <table v-align-numbers class="table" v-else-if="store.scrapeLogs.length">
        <thead>
          <tr>
            <th class="sortable" @click="toggleSort('source')">Source {{ sortIndicator('source') }}</th>
            <th class="sortable" @click="toggleSort('status')">Status {{ sortIndicator('status') }}</th>
            <th class="sortable" @click="toggleSort('records_processed')">Records {{ sortIndicator('records_processed') }}</th>
            <th>Message</th>
            <th class="sortable" @click="toggleSort('created_at')">When {{ sortIndicator('created_at') }}</th>
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
      <p v-else class="muted">No scrape activity logged yet.</p>
    </div>
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
