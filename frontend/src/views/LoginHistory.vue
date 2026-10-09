<script setup>
import { onMounted, ref } from 'vue'
import * as authApi from '../api/auth'
import { useSortableTable } from '../composables/useSortableTable'
import { deviceLabel as device, locationLabel as location } from '../utils/device'

const history = ref([])
const loading = ref(true)

const historyTable = useSortableTable(history, { defaultKey: 'logged_in_at', defaultDir: 'desc' })
const { sorted } = historyTable

async function load() {
  loading.value = true
  try {
    history.value = await authApi.loginHistory()
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <LoadingState v-if="loading" style="margin-top: 16px" />

    <Card v-else style="margin-top: 16px">
      <p class="muted">{{ sorted.length }} login{{ sorted.length === 1 ? '' : 's' }} recorded.</p>
      <table v-align-numbers class="table">
        <thead>
          <tr>
            <SortableTh :table="historyTable" column="logged_in_at">When</SortableTh>
            <SortableTh :table="historyTable" column="ip_address">IP Address</SortableTh>
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
    </Card>
  </div>
</template>
