<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import * as marketApi from '../../api/market'
import { useAuthStore } from '../../stores/auth'
import { useStocksStore } from '../../stores/stocks'
import { formatPrice } from '../../utils/format'
import { pageTitleOverride } from '../../composables/usePageTitle'
import { getNotificationPrefs } from '../../utils/notificationPrefs'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const stocksStore = useStocksStore()

const alerts = ref([])
const showAlerts = ref(false)
let alertTimer = null

async function loadAlerts() {
  try {
    const data = await marketApi.priceAlerts()
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

// A page whose title depends on its data (a stock, a sector) overrides the route's static title.
const title = computed(() => pageTitleOverride.value || route.meta.title || 'Share Market Signals')
const lastScrape = computed(() => stocksStore.scrapeLogs[0] || null)

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

      <div v-if="lastScrape" class="scrape-block">
        <span class="muted scrape-msg">
          Last synced: {{ new Date(lastScrape.created_at).toLocaleTimeString() }}
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
  box-shadow: var(--shadow-sm);
  position: sticky;
  top: 0;
  z-index: 10;
}

.page-title {
  margin: 0;
  font-size: 1.15rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis; /* a long company name must not push the bell and user menu off the bar */
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
  border-radius: var(--radius-sm);
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
  border-radius: var(--radius);
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
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.82rem;
  transition: background-color 0.15s ease;
}

.link-btn:hover {
  background: #f1f5f9;
}
</style>
