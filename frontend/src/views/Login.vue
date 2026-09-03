<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function handleSubmit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login({ email: email.value, password: password.value })
    router.push(route.query.redirect || { name: 'dashboard' })
  } catch (e) {
    error.value = e.response?.data?.message || 'Login failed.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-page">
    <form class="card form-stack" @submit.prevent="handleSubmit">
      <h1>Share Market Signals</h1>
      <p class="muted">Log in to view today's buy/sell signals.</p>
      <label>
        Email
        <input v-model="email" type="email" class="input" required autofocus />
      </label>
      <label>
        Password
        <input v-model="password" type="password" class="input" required />
      </label>
      <p v-if="error" class="error-text">{{ error }}</p>
      <button class="btn" type="submit" :disabled="loading">{{ loading ? 'Logging in…' : 'Log in' }}</button>
      <p class="muted">No account? <RouterLink to="/register">Register</RouterLink></p>
    </form>
  </div>
</template>

<style scoped>
.auth-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg);
}

label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 0.85rem;
  color: var(--text-muted);
}
</style>
