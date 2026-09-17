<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref(route.query.email || '')
const password = ref('')
const passwordConfirmation = ref('')
const error = ref('')
const message = ref('')
const loading = ref(false)

async function handleSubmit() {
  error.value = ''
  message.value = ''
  loading.value = true
  try {
    message.value = await auth.resetPassword({
      token: route.query.token,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    setTimeout(() => router.push({ name: 'login' }), 1500)
  } catch (e) {
    const errors = e.response?.data?.errors
    error.value = errors ? Object.values(errors).flat().join(' ') : e.response?.data?.message || 'Reset failed.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-page">
    <form class="card form-stack" @submit.prevent="handleSubmit">
      <h1>Reset password</h1>
      <p v-if="!route.query.token" class="error-text">This link is missing its reset token — use the link from your email.</p>
      <label>
        Email
        <input v-model="email" type="email" class="input" required />
      </label>
      <label>
        New password
        <input v-model="password" type="password" class="input" required autofocus />
      </label>
      <label>
        Confirm new password
        <input v-model="passwordConfirmation" type="password" class="input" required />
      </label>
      <p v-if="message" class="muted">{{ message }} Redirecting to log in…</p>
      <p v-if="error" class="error-text">{{ error }}</p>
      <button class="btn" type="submit" :disabled="loading || !route.query.token">{{ loading ? 'Resetting…' : 'Reset password' }}</button>
      <p class="muted"><RouterLink to="/login">Back to log in</RouterLink></p>
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
