<script setup>
import { computed, onMounted, ref } from 'vue'
import client from '../api/client'
import { useStocksStore } from '../stores/stocks'
import { useSortableTable } from '../composables/useSortableTable'

const store = useStocksStore()
const schedule = ref([])
const loading = ref(true)

const { sorted: sortedLogs, toggleSort, sortIndicator } = useSortableTable(
  computed(() => store.scrapeLogs),
  { defaultKey: 'created_at', defaultDir: 'desc' }
)

async function load() {
  loading.value = true
  const [scheduleRes] = await Promise.all([client.get('/schedule'), store.fetchScrapeLogs()])
  schedule.value = scheduleRes.data
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Data Source / Scrape Settings</h1>
    <p class="muted">
      There's no UI yet to change the schedule itself (that lives in the backend's <code>routes/console.php</code>) —
      this page shows what's currently configured and what's actually run recently. Use "Scrape Latest Data" in the
      topbar any time for an immediate manual refresh.
    </p>

    <div class="card" style="margin-top: 16px">
      <h3>Configured Schedule</h3>
      <p class="muted">Read directly from the backend's registered schedule — always matches what's actually configured, not a hand-copied list.</p>
      <p v-if="loading" class="muted">Loading…</p>
      <table class="table" v-else>
        <thead><tr><th>Command</th><th>Runs</th><th>What it does</th></tr></thead>
        <tbody>
          <tr v-for="s in schedule" :key="s.command">
            <td><code>{{ s.command }}</code></td>
            <td class="muted">{{ s.schedule_description }} {{ s.timezone }}</td>
            <td class="muted">{{ s.description || '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

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
</style>
