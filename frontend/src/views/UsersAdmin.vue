<script setup>
import { onMounted, ref } from 'vue'
import client from '../api/client'
import { useSortableTable } from '../composables/useSortableTable'

const users = ref([])
const loading = ref(true)

const { sorted, toggleSort, sortIndicator } = useSortableTable(users)

async function load() {
  loading.value = true
  const { data } = await client.get('/users')
  users.value = data
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Users</h1>
    <p class="muted">No role/permission system exists yet — every logged-in user has full access. This is just the registered account list, not an admin panel with permissions to manage.</p>

    <p v-if="loading" class="muted">Loading…</p>

    <div v-else class="card">
      <table class="table">
        <thead>
          <tr>
            <th class="sortable" @click="toggleSort('name')">Name {{ sortIndicator('name') }}</th>
            <th class="sortable" @click="toggleSort('email')">Email {{ sortIndicator('email') }}</th>
            <th class="sortable" @click="toggleSort('created_at')">Joined {{ sortIndicator('created_at') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in sorted" :key="u.id">
            <td>{{ u.name }}</td>
            <td class="muted">{{ u.email }}</td>
            <td class="muted">{{ new Date(u.created_at).toLocaleDateString() }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
