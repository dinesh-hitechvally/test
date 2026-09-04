<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'

const screens = ref([])
const loading = ref(true)
const deletingId = ref(null)

async function load() {
  loading.value = true
  const { data } = await client.get('/saved-screens')
  screens.value = data
  loading.value = false
}

async function remove(id) {
  if (!window.confirm('Delete this saved screen?')) return
  deletingId.value = id
  try {
    await client.delete(`/saved-screens/${id}`)
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
    <h1>Saved Screens</h1>
    <p class="muted">
      Your saved filter combinations from the Custom Screener. Save a new one from
      <RouterLink :to="{ name: 'screener' }">Custom Screener</RouterLink>.
    </p>

    <p v-if="loading" class="muted">Loading…</p>

    <div v-else class="card">
      <table class="table" v-if="screens.length">
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
      <p v-else class="muted">No saved screens yet.</p>
    </div>
  </div>
</template>
