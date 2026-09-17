<script setup>
import { onMounted, ref } from 'vue'
import client from '../api/client'
import { useSortableTable } from '../composables/useSortableTable'

const history = ref([])
const loading = ref(true)

const { sorted, toggleSort, sortIndicator } = useSortableTable(history, { defaultKey: 'logged_in_at', defaultDir: 'desc' })

function location(row) {
  return [row.city, row.region, row.country].filter(Boolean).join(', ') || '—'
}

// A user agent string is long and mostly noise ("Mozilla/5.0 (...) AppleWebKit/537.36 ...") —
// this pulls out just the browser/OS pair most people actually recognize.
function device(userAgent) {
  if (!userAgent) return 'Unknown'

  const os = /Windows/.test(userAgent) ? 'Windows'
    : /Mac OS X/.test(userAgent) ? 'macOS'
    : /Android/.test(userAgent) ? 'Android'
    : /iPhone|iPad/.test(userAgent) ? 'iOS'
    : /Linux/.test(userAgent) ? 'Linux'
    : 'Unknown OS'

  const browser = /Edg\//.test(userAgent) ? 'Edge'
    : /Chrome\//.test(userAgent) ? 'Chrome'
    : /Firefox\//.test(userAgent) ? 'Firefox'
    : /Safari\//.test(userAgent) ? 'Safari'
    : 'Unknown browser'

  return `${browser} on ${os}`
}

async function load() {
  loading.value = true
  const { data } = await client.get('/user/login-history')
  history.value = data
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Login History</h1>
    <p class="muted">The last 50 times your account signed in — IP address, approximate location, and device.</p>

    <p v-if="loading" class="muted" style="margin-top: 16px">Loading…</p>

    <div v-else class="card" style="margin-top: 16px">
      <p class="muted">{{ sorted.length }} login{{ sorted.length === 1 ? '' : 's' }} recorded.</p>
      <table class="table">
        <thead>
          <tr>
            <th class="sortable" @click="toggleSort('logged_in_at')">When {{ sortIndicator('logged_in_at') }}</th>
            <th class="sortable" @click="toggleSort('ip_address')">IP Address {{ sortIndicator('ip_address') }}</th>
            <th>Location</th>
            <th>Device</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in sorted" :key="row.id">
            <td>{{ new Date(row.logged_in_at).toLocaleString() }}</td>
            <td class="muted">{{ row.ip_address }}</td>
            <td class="muted">{{ location(row) }}</td>
            <td class="muted">{{ device(row.user_agent) }}</td>
          </tr>
          <tr v-if="sorted.length === 0">
            <td colspan="4" class="muted" style="text-align: center; padding: 24px">No logins recorded yet.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
