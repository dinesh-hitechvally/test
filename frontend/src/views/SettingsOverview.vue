<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useStocksStore } from '../stores/stocks'
import { getNotificationPrefs } from '../utils/notificationPrefs'

const auth = useAuthStore()
const stocksStore = useStocksStore()

const userCount = ref(null)
const scheduleCount = ref(null)
const loading = ref(true)
const prefs = getNotificationPrefs()

const lastScrape = computed(() => stocksStore.scrapeLogs[0] || null)

const SECTIONS = [
  {
    to: 'settings-profile',
    label: 'Profile',
    description: 'Your name, email, and password.',
  },
  {
    to: 'settings-notifications',
    label: 'Notification Preferences',
    description: 'Which topbar alerts you see, and how often they refresh.',
  },
  {
    to: 'settings-data-source',
    label: 'Data Source / Scrape Settings',
    description: 'The real scraping schedule and recent scrape activity.',
  },
  {
    to: 'settings-admin',
    label: 'Users',
    description: 'Everyone with an account on this instance.',
  },
]

async function load() {
  loading.value = true
  const [usersRes, scheduleRes] = await Promise.all([
    client.get('/users'),
    client.get('/schedule'),
    stocksStore.fetchScrapeLogs(),
  ])
  userCount.value = usersRes.data.length
  scheduleCount.value = scheduleRes.data.length
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Settings</h1>
    <p class="muted">Account, notifications, data source, and the people on this instance.</p>

    <div class="card" style="margin-top: 16px">
      <div class="account-row">
        <div>
          <p class="muted small">Signed in as</p>
          <p class="account-name">{{ auth.user?.name }}</p>
          <p class="muted">{{ auth.user?.email }}</p>
        </div>
        <div v-if="!loading" class="account-stats">
          <div>
            <p class="muted small">Users</p>
            <p class="stat">{{ userCount }}</p>
          </div>
          <div>
            <p class="muted small">Scheduled jobs</p>
            <p class="stat">{{ scheduleCount }}</p>
          </div>
          <div>
            <p class="muted small">Last scrape</p>
            <p class="stat">{{ lastScrape ? new Date(lastScrape.created_at).toLocaleTimeString() : 'Never' }}</p>
          </div>
          <div>
            <p class="muted small">Alert refresh</p>
            <p class="stat">{{ prefs.pollIntervalSeconds }}s</p>
          </div>
        </div>
      </div>
    </div>

    <div class="grid" style="grid-template-columns: 1fr 1fr; margin-top: 16px">
      <RouterLink v-for="s in SECTIONS" :key="s.to" :to="{ name: s.to }" class="card section-card">
        <h3>{{ s.label }}</h3>
        <p class="muted">{{ s.description }}</p>
      </RouterLink>
    </div>
  </div>
</template>

<style scoped>
.account-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
}

.account-name {
  margin: 2px 0;
  font-size: 1.2rem;
  font-weight: 700;
}

.account-stats {
  display: flex;
  gap: 28px;
}

.stat {
  margin: 2px 0 0;
  font-size: 1.2rem;
  font-weight: 700;
}

.small {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  font-weight: 600;
  margin: 0 0 2px;
}

.section-card {
  text-decoration: none;
  color: inherit;
  display: block;
  transition: box-shadow 0.15s, border-color 0.15s;
}

.section-card:hover {
  border-color: #2563eb;
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
}

.section-card h3 {
  margin: 0 0 6px;
}

.section-card p {
  margin: 0;
}
</style>
