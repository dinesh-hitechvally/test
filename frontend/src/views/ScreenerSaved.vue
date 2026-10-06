<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import * as watchlistsApi from '../api/watchlists'

const screens = ref([])
const loading = ref(true)
const deletingId = ref(null)

async function load() {
  loading.value = true
  screens.value = await watchlistsApi.savedScreens()
  loading.value = false
}

async function remove(id) {
  if (!window.confirm('Delete this saved screen?')) return
  deletingId.value = id
  try {
    await watchlistsApi.deleteSavedScreen(id)
    screens.value = screens.value.filter((s) => s.id !== id)
  } finally {
    deletingId.value = null
  }
}

function summarize(filters) {
  const parts = []
  if (filters.signal) parts.push(`signal=${filters.signal}`)
  if (filters.sector) parts.push(`sector=${filters.sector}`)
  if (filters.rsiMin || filters.rsiMax) parts.push(`RSI ${filters.rsiMin || '–'} to ${filters.rsiMax || '–'}`)
  if (filters.changeMin || filters.changeMax) parts.push(`% change ${filters.changeMin || '–'} to ${filters.changeMax || '–'}`)
  if (filters.priceMin || filters.priceMax) parts.push(`price ${filters.priceMin || '–'} to ${filters.priceMax || '–'}`)
  if (filters.sma) parts.push(`SMA20 ${filters.sma} SMA50`)
  return parts.length ? parts.join(', ') : 'No filters set'
}

onMounted(load)
</script>

<template>
  <div>
    <LoadingState v-if="loading" />

    <Card v-else>
      <table v-align-numbers class="table" v-if="screens.length">
        <thead><tr><th>Name</th><th>Filters</th><th>Saved</th><th></th></tr></thead>
        <tbody>
          <tr v-for="s in screens" :key="s.id">
            <td><strong>{{ s.name }}</strong></td>
            <td class="muted">{{ summarize(s.filters) }}</td>
            <td class="muted">{{ new Date(s.created_at).toLocaleDateString() }}</td>
            <td>
              <RouterLink :to="{ name: 'screener', query: { load: s.id } }" class="btn-secondary btn">Load</RouterLink>
              <button class="btn-secondary btn" :disabled="deletingId === s.id" @click="remove(s.id)">
                {{ deletingId === s.id ? 'Deleting…' : 'Delete' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <EmptyState v-else>No saved screens yet.</EmptyState>
    </Card>
  </div>
</template>
