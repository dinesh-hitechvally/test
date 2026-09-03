<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const error = ref('')
const loading = ref(false)

async function handleSubmit() {
  error.value = ''
  loading.value = true
  try {
    await auth.register({
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    router.push({ name: 'dashboard' })
  } catch (e) {
    const errors = e.response?.data?.errors
    error.value = errors ? Object.values(errors).flat().join(' ') : 'Registration failed.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-page">
    <form class="card form-stack" @submit.prevent="handleSubmit">
      <h1>Create account</h1>
      <label>
        Name
        <input v-model="name" type="text" class="input" required autofocus />
      </label>
      <label>
        Email
        <input v-model="email" type="email" class="input" required />
      </label>
      <label>
        Password
        <input v-model="password" type="password" class="input" required />
      </label>
      <label>
        Confirm password
        <input v-model="passwordConfirmation" type="password" class="input" required />
      </label>
      <p v-if="error" class="error-text">{{ error }}</p>
      <button class="btn" type="submit" :disabled="loading">{{ loading ? 'Creating…' : 'Create account' }}</button>
      <p class="muted">Already have an account? <RouterLink to="/login">Log in</RouterLink></p>
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
