<script setup>
import { onMounted, ref } from 'vue'
import * as authApi from '../api/auth'
import { useSortableTable } from '../composables/useSortableTable'

const users = ref([])
const loading = ref(true)

const usersTable = useSortableTable(users)
const { sorted } = usersTable

async function load() {
  loading.value = true
  users.value = await authApi.users()
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <p class="muted">No role/permission system exists yet — every logged-in user has full access. This is just the registered account list, not an admin panel with permissions to manage.</p>

    <LoadingState v-if="loading" />

    <Card v-else>
      <table v-align-numbers class="table">
        <thead>
          <tr>
            <SortableTh :table="usersTable" column="name">Name</SortableTh>
            <SortableTh :table="usersTable" column="email">Email</SortableTh>
            <SortableTh :table="usersTable" column="created_at">Joined</SortableTh>
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
    </Card>
  </div>
</template>
