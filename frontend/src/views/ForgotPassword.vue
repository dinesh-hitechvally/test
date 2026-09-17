<script setup>
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()

const email = ref('')
const message = ref('')
const error = ref('')
const loading = ref(false)

async function handleSubmit() {
  error.value = ''
  message.value = ''
  loading.value = true
  try {
    message.value = await auth.forgotPassword(email.value)
  } catch (e) {
    error.value = e.response?.data?.message || 'Something went wrong — try again.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-page">
    <form class="card form-stack" @submit.prevent="handleSubmit">
      <h1>Forgot password</h1>
      <p class="muted">Enter your account email and we'll send you a link to reset your password.</p>
      <label>
        Email
        <input v-model="email" type="email" class="input" required autofocus />
      </label>
      <p v-if="message" class="muted">{{ message }}</p>
      <p v-if="error" class="error-text">{{ error }}</p>
      <button class="btn" type="submit" :disabled="loading">{{ loading ? 'Sending…' : 'Send reset link' }}</button>
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
