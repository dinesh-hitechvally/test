<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import client from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useStocksStore } from '../stores/stocks'
import { formatPrice } from '../utils/format'
import { getNotificationPrefs } from '../utils/notificationPrefs'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const stocksStore = useStocksStore()

const alerts = ref([])
const showAlerts = ref(false)
let alertTimer = null

async function loadAlerts() {
  try {
    const { data } = await client.get('/alerts')
    const prefs = getNotificationPrefs()
    alerts.value = data.filter((a) => {
      if (a.kind !== 'portfolio') return true // watchlist price alerts aren't covered by the stop/target toggles
      return a.status === 'stop_breached' ? prefs.showStopLossAlerts : prefs.showTargetAlerts
    })
  } catch {
    // Silent — alerts are a convenience, not core functionality; a failed
    // poll shouldn't surface an error to the user, just try again next tick.
  }
}

function toggleAlerts() {
  showAlerts.value = !showAlerts.value
}

function handleAlertsBlur() {
  setTimeout(() => {
    showAlerts.value = false
  }, 150)
}

function alertLabel(alert) {
  if (alert.kind === 'watchlist') {
    return alert.alert_direction === 'above' ? 'Price alert (above)' : 'Price alert (below)'
  }

  return alert.status === 'stop_breached' ? 'Stop hit' : 'Target hit'
}

const title = computed(() => route.meta.title || 'Share Market Signals')
const lastScrape = computed(() => stocksStore.scrapeLogs[0] || null)

const searchTerm = ref('')
const showResults = ref(false)

const matches = computed(() => {
  const term = searchTerm.value.trim().toLowerCase()
  if (!term) return []
  return stocksStore.stocks
    .filter((s) => s.symbol.toLowerCase().includes(term) || (s.company_name || '').toLowerCase().includes(term))
    .slice(0, 8)
})

function goToStock(symbol) {
  searchTerm.value = ''
  showResults.value = false
  router.push({ name: 'stock-detail', params: { symbol } })
}

function handleEnter() {
  if (matches.value.length > 0) goToStock(matches.value[0].symbol)
}

function handleSearchBlur() {
  // delay so a click on a result registers before the list unmounts
  setTimeout(() => {
    showResults.value = false
  }, 150)
}

const scraping = ref(false)
const scrapeMessage = ref('')

async function handleScrape() {
  scraping.value = true
  scrapeMessage.value = ''
  try {
    const result = await stocksStore.runScrape()
    scrapeMessage.value = `Updated ${result.updated_prices} (${result.created_stocks} new)`
    await stocksStore.fetchScrapeLogs()
  } catch {
    scrapeMessage.value = stocksStore.lastError
  } finally {
    scraping.value = false
  }
}

async function handleLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}

onMounted(() => {
  loadAlerts()
  const pollMs = getNotificationPrefs().pollIntervalSeconds * 1000
  alertTimer = setInterval(loadAlerts, pollMs)
})

onUnmounted(() => {
  if (alertTimer) clearInterval(alertTimer)
})
</script>

<template>
  <header class="topbar">
    <h1 class="page-title">{{ title }}</h1>

    <div class="search-wrap">
      <input
        v-model="searchTerm"
        class="input search-input"
        placeholder="Jump to a stock…"
        @focus="showResults = true"
        @blur="handleSearchBlur"
        @keyup.enter="handleEnter"
      />
      <div v-if="showResults && matches.length" class="search-results">
        <button v-for="m in matches" :key="m.id" class="search-result" @mousedown.prevent="goToStock(m.symbol)">
          <strong>{{ m.symbol }}</strong>
          <span class="muted">{{ m.company_name }}</span>
        </button>
      </div>
    </div>

    <div class="topbar-actions">
      <div class="alerts-wrap">
        <button class="bell-btn" @click="toggleAlerts" @blur="handleAlertsBlur">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
          </svg>
          <span v-if="alerts.length" class="alert-count">{{ alerts.length }}</span>
        </button>
        <div v-if="showAlerts" class="alerts-dropdown">
          <div class="alerts-header">Alerts</div>
          <div v-if="alerts.length === 0" class="muted alerts-empty">No stop-loss, target, or watchlist price alerts right now.</div>
          <button
            v-for="a in alerts"
            :key="`${a.kind}-${a.stock_id}`"
            class="alert-row"
            @mousedown.prevent="router.push(a.kind === 'watchlist' ? { name: 'watchlist' } : { name: 'portfolio' })"
          >
            <span class="badge" :class="a.kind === 'watchlist' ? (a.alert_direction === 'above' ? 'buy' : 'sell') : (a.status === 'stop_breached' ? 'sell' : 'buy')">
              {{ alertLabel(a) }}
            </span>
            <span class="alert-symbol">{{ a.symbol }}</span>
            <span class="muted">Rs. {{ formatPrice(a.current_price) }} in {{ a.kind === 'watchlist' ? a.watchlist_name : a.portfolio_name }}</span>
          </button>
        </div>
      </div>

      <div class="scrape-block">
        <button class="btn" :disabled="scraping" @click="handleScrape">
          {{ scraping ? 'Scraping…' : 'Scrape Latest Data' }}
        </button>
        <span v-if="scrapeMessage" class="muted scrape-msg">{{ scrapeMessage }}</span>
        <span v-else-if="lastScrape" class="muted scrape-msg">
          Last: {{ new Date(lastScrape.created_at).toLocaleTimeString() }}
        </span>
      </div>

      <div class="user-area">
        <span>{{ auth.user?.name }}</span>
        <button class="link-btn" @click="handleLogout">Log out</button>
      </div>
    </div>
  </header>
</template>

<style scoped>
.topbar {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 14px 24px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  box-shadow: 0 1px 0 rgba(15, 23, 42, 0.03), 0 2px 8px rgba(15, 23, 42, 0.03);
  position: sticky;
  top: 0;
  z-index: 10;
}

.page-title {
  margin: 0;
  font-size: 1.15rem;
  white-space: nowrap;
}

.search-wrap {
  position: relative;
  flex: 1;
  max-width: 320px;
}

.search-input {
  width: 100%;
}

.search-results {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 8px;
  box-shadow: var(--shadow-lg);
  z-index: 20;
  overflow: hidden;
}

.search-result {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  width: 100%;
  padding: 8px 12px;
  background: none;
  border: none;
  border-bottom: 1px solid var(--border);
  cursor: pointer;
  text-align: left;
  font-size: 0.85rem;
}

.search-result:last-child {
  border-bottom: none;
}

.search-result:hover {
  background: #f1f5f9;
}

.topbar-actions {
  display: flex;
  align-items: center;
  gap: 20px;
  margin-left: auto;
}

.alerts-wrap {
  position: relative;
}

.bell-btn {
  position: relative;
  background: none;
  border: 1px solid var(--border);
  color: var(--text-muted);
  border-radius: 8px;
  padding: 7px 9px;
  cursor: pointer;
  display: flex;
  align-items: center;
  transition: background-color 0.15s ease, border-color 0.15s ease;
}

.bell-btn:hover {
  background: #f1f5f9;
}

.alert-count {
  position: absolute;
  top: -5px;
  right: -5px;
  background: var(--strong-sell);
  color: #fff;
  font-size: 0.68rem;
  font-weight: 700;
  line-height: 1;
  padding: 3px 5px;
  border-radius: 999px;
  min-width: 16px;
  text-align: center;
}

.alerts-dropdown {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  width: 320px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 8px;
  box-shadow: var(--shadow-lg);
  z-index: 20;
  overflow: hidden;
}

.alerts-header {
  padding: 10px 14px;
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--text-muted);
  border-bottom: 1px solid var(--border);
}

.alerts-empty {
  padding: 16px 14px;
  font-size: 0.85rem;
}

.alert-row {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 10px 14px;
  background: none;
  border: none;
  border-bottom: 1px solid var(--border);
  cursor: pointer;
  text-align: left;
  font-size: 0.82rem;
}

.alert-row:last-child {
  border-bottom: none;
}

.alert-row:hover {
  background: #f1f5f9;
}

.alert-symbol {
  font-weight: 700;
}

.scrape-block {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
}

.scrape-msg {
  font-size: 0.75rem;
}

.user-area {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 0.88rem;
  color: var(--text-muted);
  white-space: nowrap;
}

.link-btn {
  background: none;
  border: 1px solid var(--border);
  color: var(--text-muted);
  padding: 5px 10px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.82rem;
}

.link-btn:hover {
  background: #f1f5f9;
}
</style>
