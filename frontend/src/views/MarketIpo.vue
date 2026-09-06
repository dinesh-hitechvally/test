<script setup>
import { onMounted, ref } from 'vue'
import client from '../api/client'
import { formatPrice } from '../utils/format'
import { useSortableTable } from '../composables/useSortableTable'

const open = ref([])
const upcoming = ref([])
const loading = ref(true)

const { sorted: sortedOpen, toggleSort: toggleOpenSort, sortIndicator: openSortIndicator } = useSortableTable(open)
const { sorted: sortedUpcoming, toggleSort: toggleUpcomingSort, sortIndicator: upcomingSortIndicator } = useSortableTable(upcoming)

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
            <tr>
              <th class="sortable" @click="toggleOpenSort('symbol')">Symbol {{ openSortIndicator('symbol') }}</th>
              <th class="sortable" @click="toggleOpenSort('company_name')">Company {{ openSortIndicator('company_name') }}</th>
              <th class="sortable" @click="toggleOpenSort('units')">Units {{ openSortIndicator('units') }}</th>
              <th class="sortable" @click="toggleOpenSort('price')">Price {{ openSortIndicator('price') }}</th>
              <th class="sortable" @click="toggleOpenSort('opening_date')">Opening {{ openSortIndicator('opening_date') }}</th>
              <th class="sortable" @click="toggleOpenSort('closing_date')">Closing {{ openSortIndicator('closing_date') }}</th>
              <th class="sortable" @click="toggleOpenSort('status')">Status {{ openSortIndicator('status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in sortedOpen" :key="row.id">
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
            <tr>
              <th class="sortable" @click="toggleUpcomingSort('symbol')">Symbol {{ upcomingSortIndicator('symbol') }}</th>
              <th class="sortable" @click="toggleUpcomingSort('company_name')">Company {{ upcomingSortIndicator('company_name') }}</th>
              <th class="sortable" @click="toggleUpcomingSort('units')">Units {{ upcomingSortIndicator('units') }}</th>
              <th class="sortable" @click="toggleUpcomingSort('sector')">Sector {{ upcomingSortIndicator('sector') }}</th>
              <th class="sortable" @click="toggleUpcomingSort('remark')">Issue Manager {{ upcomingSortIndicator('remark') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in sortedUpcoming" :key="row.id">
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
