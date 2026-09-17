<script setup>
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth'
import AuthLayout from '../components/AuthLayout.vue'
import NavIcon from '../components/NavIcon.vue'

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
  <AuthLayout title="Forgot password" subtitle="Enter your account email and we'll send you a link to reset your password.">
    <form class="form-stack" @submit.prevent="handleSubmit">
      <label>
        Email
        <span class="input-icon-wrap">
          <span class="input-icon"><NavIcon name="mail" /></span>
          <input v-model="email" type="email" class="input" required autofocus autocomplete="email" />
        </span>
      </label>
      <p v-if="message" class="muted">{{ message }}</p>
      <p v-if="error" class="error-text">{{ error }}</p>
      <button class="btn btn-block" type="submit" :disabled="loading">{{ loading ? 'Sending…' : 'Send reset link' }}</button>
      <p class="muted center back-link">
        <RouterLink to="/login"><NavIcon name="chevron-left" /> Back to log in</RouterLink>
      </p>
    </form>
  </AuthLayout>
</template>

<style scoped>
label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 0.85rem;
  color: var(--text-muted);
}

.btn-block {
  width: 100%;
  padding: 11px 16px;
  font-size: 0.95rem;
}

.center {
  text-align: center;
}

.back-link a {
  display: inline-flex;
  align-items: center;
  gap: 2px;
}
</style>
