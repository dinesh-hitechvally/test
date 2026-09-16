<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { useStocksStore } from '../stores/stocks'
import { useSortableTable } from '../composables/useSortableTable'

const store = useStocksStore()
const jobs = ref([])
const flaggedStocks = ref([])
const secretConfigured = ref(true)
const loading = ref(true)
const copiedPath = ref('')

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
  jobs.value = scheduleRes.data.jobs
  flaggedStocks.value = scheduleRes.data.flagged_stocks
  secretConfigured.value = scheduleRes.data.secret_configured
  loading.value = false
}

const sourceLabels = { history: 'Full History', sector: 'Sector' }

// Split by what the route actually does, not just grouped for display —
// scrape hits nepalstock.com/ShareSansar, reports only recomputes from data
// already in the DB (see routes/web.php's cron/scrape vs cron/reports).
const scrapeJobs = computed(() => jobs.value.filter((j) => j.group === 'scrape'))
const reportJobs = computed(() => jobs.value.filter((j) => j.group === 'reports'))

async function copyUrl(url) {
  try {
    await navigator.clipboard.writeText(url)
    copiedPath.value = url
    setTimeout(() => {
      if (copiedPath.value === url) copiedPath.value = ''
    }, 1500)
  } catch {
    // Clipboard access can be blocked (permissions, non-HTTPS context) —
    // the URL is still shown on screen to copy manually either way.
  }
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Data Source / Scrape Settings</h1>
    <p class="muted">
      The pipeline runs via URL, not server cron — paste each URL below into an external scheduler (e.g.
      <a href="https://cron-job.org" target="_blank" rel="noopener">cron-job.org</a>, UptimeRobot, or a plain crontab
      <code>curl</code> line on any box you do have) set to the suggested cadence. This works even on hosting with no
      real cron/SSH access. Use "Scrape Latest Data" in the topbar any time for an immediate manual refresh instead.
    </p>

    <p v-if="!loading && !secretConfigured" class="error-text card" style="margin-top: 12px">
      <code>CRON_SECRET</code> isn't set in the backend's <code>.env</code> — every URL below will 403 until it is.
      Set it, then reload this page to see the real URLs.
    </p>

    <div v-if="!loading" class="card" style="margin-top: 12px">
      <h3>Stocks with Data Issues <span v-if="sortedFlagged.length" class="badge sell">{{ sortedFlagged.length }}</span></h3>
      <p class="muted">
        A stock lands here when its last full-history, sector, or dividend fetch failed — not a retry, just a flag so a
        failure doesn't sit silently in a log file. Cleared automatically the next time that same fetch succeeds (e.g.
        re-ping <code>scrape/fetch-history/&lt;symbol&gt;</code>, <code>scrape/sync-sectors</code>, or
        <code>scrape/sync-dividends</code>).
      </p>
      <table class="table" v-if="sortedFlagged.length">
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

    <p class="muted">
      "When" is NEPSE's own local time (Asia/Kathmandu, NPT) — use it directly if your pinger supports a per-job
      timezone. Otherwise use the UTC cron expression: NPT is UTC+5:45, not a whole number of hours, so it's not
      just "subtract 6" — the minute shifts too, and the weekly Monday-NPT jobs fall on <strong>Sunday</strong> in UTC.
    </p>
    <p v-if="loading" class="muted">Loading…</p>

    <template v-else>
      <div class="card" style="margin-top: 16px">
        <h3>Scrape</h3>
        <p class="muted">Hits nepalstock.com or ShareSansar and writes what comes back — the only routes that make an external call.</p>
        <table class="table">
          <thead><tr><th>Command</th><th>When (NPT)</th><th>Cron (UTC)</th><th>What it does</th><th>URL</th></tr></thead>
          <tbody>
            <tr v-for="j in scrapeJobs" :key="j.command">
              <td><code>{{ j.command }}</code></td>
              <td class="muted">{{ j.when }}<br /><code>{{ j.cron_npt }}</code></td>
              <td class="muted"><code>{{ j.cron_utc }}</code></td>
              <td class="muted">{{ j.description || '—' }}</td>
              <td>
                <div class="url-row">
                  <a :href="j.url" target="_blank" rel="noopener" class="url-link">{{ j.url }}</a>
                  <button type="button" class="btn-secondary btn small" @click="copyUrl(j.url)">
                    {{ copiedPath === j.url ? 'Copied!' : 'Copy' }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>Reports</h3>
        <p class="muted">No external call — only recomputes indicators/signals/ML from data this app already has.</p>
        <table class="table">
          <thead><tr><th>Command</th><th>When (NPT)</th><th>Cron (UTC)</th><th>What it does</th><th>URL</th></tr></thead>
          <tbody>
            <tr v-for="j in reportJobs" :key="j.command">
              <td><code>{{ j.command }}</code></td>
              <td class="muted">{{ j.when }}<br /><code>{{ j.cron_npt }}</code></td>
              <td class="muted"><code>{{ j.cron_utc }}</code></td>
              <td class="muted">{{ j.description || '—' }}</td>
              <td>
                <div class="url-row">
                  <a :href="j.url" target="_blank" rel="noopener" class="url-link">{{ j.url }}</a>
                  <button type="button" class="btn-secondary btn small" @click="copyUrl(j.url)">
                    {{ copiedPath === j.url ? 'Copied!' : 'Copy' }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <div class="card" style="margin-top: 16px">
      <h3>Recent Scrape Activity</h3>
      <p v-if="loading" class="muted">Loading…</p>
      <table class="table" v-else-if="store.scrapeLogs.length">
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

.url-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.url-link {
  font-size: 0.78rem;
  word-break: break-all;
  max-width: 320px;
}

.small {
  font-size: 0.78rem;
  padding: 4px 10px;
  white-space: nowrap;
}
</style>
