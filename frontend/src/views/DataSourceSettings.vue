<script setup>
import { onMounted, ref } from 'vue'
import { useStocksStore } from '../stores/stocks'

const store = useStocksStore()
const loading = ref(true)

const SCHEDULE = [
  { command: 'market:sync', when: 'Weekdays at 15:30 NPT', description: 'Scrapes today\'s prices from the official nepalstock.com API for every stock.' },
  { command: 'market:sync-index', when: 'Weekdays at 15:32 NPT', description: 'Snapshots the NEPSE Index and sub-indices.' },
  { command: 'stocks:queue-missing-history', when: 'Weekdays at 15:35 NPT', description: 'Queues a full price-history backfill (via ShareSansar) for any stock that\'s never had one.' },
  { command: 'queue:work', when: 'Every 15 minutes, 06:00–23:00 NPT', description: 'Drains the queue above — this is what actually processes the history backfills.' },
  { command: 'stocks:backfill-sectors', when: 'Weekly, Monday 03:00 NPT', description: 'Fills in sector data for any stock still missing it.' },
  { command: 'ml:train-predictor', when: 'Weekly, Monday 03:30 NPT', description: 'Retrains the ML direction predictor on the latest pooled data.' },
  { command: 'forecast:backtest', when: 'Weekly, Monday 03:45 NPT', description: 'Re-measures the statistical (Holt) forecast\'s real accuracy.' },
  { command: 'signals:backtest-accuracy', when: 'Weekly, Monday 04:00 NPT', description: 'Re-measures the rule-based signal engine\'s historical win rate.' },
]

async function load() {
  loading.value = true
  await store.fetchScrapeLogs()
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
      <table class="table">
        <thead><tr><th>Command</th><th>Runs</th><th>What it does</th></tr></thead>
        <tbody>
          <tr v-for="s in SCHEDULE" :key="s.command">
            <td><code>{{ s.command }}</code></td>
            <td class="muted">{{ s.when }}</td>
            <td class="muted">{{ s.description }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="card" style="margin-top: 16px">
      <h3>Recent Scrape Activity</h3>
      <p v-if="loading" class="muted">Loading…</p>
      <table class="table" v-else-if="store.scrapeLogs.length">
        <thead><tr><th>Source</th><th>Status</th><th>Records</th><th>Message</th><th>When</th></tr></thead>
        <tbody>
          <tr v-for="log in store.scrapeLogs" :key="log.id">
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
