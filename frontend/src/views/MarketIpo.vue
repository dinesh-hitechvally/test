<script setup>
import { onMounted, ref } from 'vue'
import client from '../api/client'
import { formatPrice } from '../utils/format'

const open = ref([])
const upcoming = ref([])
const loading = ref(true)

function statusClass(status) {
  if (!status) return 'hold'
  const s = status.toLowerCase()
  if (s.includes('open')) return 'buy'
  if (s.includes('closed')) return 'sell'
  return 'hold'
}

async function load() {
  loading.value = true
  const { data } = await client.get('/ipo')
  open.value = data.open
  upcoming.value = data.upcoming
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>IPO / New Listings</h1>
    <p class="muted">Current and upcoming IPO issues, scraped from ShareSansar. Not financial advice.</p>

    <p v-if="loading" class="muted">Loading…</p>

    <template v-else>
      <div class="card">
        <h3>Open / Recently Closed Issues</h3>
        <table class="table" v-if="open.length">
          <thead>
            <tr><th>Symbol</th><th>Company</th><th>Units</th><th>Price</th><th>Opening</th><th>Closing</th><th>Status</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in open" :key="row.id">
              <td>
                <a v-if="row.detail_url" :href="row.detail_url" target="_blank" rel="noopener">{{ row.symbol || '—' }}</a>
                <span v-else>{{ row.symbol || '—' }}</span>
              </td>
              <td class="muted">{{ row.company_name }}</td>
              <td>{{ row.units !== null ? Number(row.units).toLocaleString() : '—' }}</td>
              <td>{{ row.price !== null ? `Rs. ${formatPrice(row.price)}` : '—' }}</td>
              <td class="muted">{{ row.opening_date || '—' }}</td>
              <td class="muted">{{ row.closing_date || '—' }}</td>
              <td><span class="badge" :class="statusClass(row.status)">{{ row.status || '—' }}</span></td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">No open issues right now.</p>
      </div>

      <div class="card" style="margin-top: 16px">
        <h3>Upcoming Issues</h3>
        <table class="table" v-if="upcoming.length">
          <thead>
            <tr><th>Symbol</th><th>Company</th><th>Units</th><th>Sector</th><th>Issue Manager</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in upcoming" :key="row.id">
              <td>
                <a v-if="row.detail_url" :href="row.detail_url" target="_blank" rel="noopener">{{ row.symbol || '—' }}</a>
                <span v-else>{{ row.symbol || '—' }}</span>
              </td>
              <td class="muted">{{ row.company_name }}</td>
              <td>{{ row.units !== null ? Number(row.units).toLocaleString() : '—' }}</td>
              <td class="muted">{{ row.sector || '—' }}</td>
              <td class="muted">{{ row.remark || '—' }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="muted">No upcoming issues announced right now.</p>
      </div>
    </template>
  </div>
</template>
