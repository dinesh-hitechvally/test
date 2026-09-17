<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import AuthLayout from '../components/AuthLayout.vue'
import NavIcon from '../components/NavIcon.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
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
  <AuthLayout title="Welcome back" subtitle="Log in to view today's buy/sell signals.">
    <form class="form-stack" @submit.prevent="handleSubmit">
      <label>
        Email
        <span class="input-icon-wrap">
          <span class="input-icon"><NavIcon name="mail" /></span>
          <input v-model="email" type="email" class="input" required autofocus autocomplete="email" />
        </span>
      </label>
      <label>
        Password
        <span class="input-icon-wrap">
          <span class="input-icon"><NavIcon name="lock" /></span>
          <input v-model="password" :type="showPassword ? 'text' : 'password'" class="input" required autocomplete="current-password" />
          <button type="button" class="input-toggle" tabindex="-1" @click="showPassword = !showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'">
            <NavIcon :name="showPassword ? 'eye-off' : 'eye'" />
          </button>
        </span>
      </label>
      <p v-if="error" class="error-text">{{ error }}</p>
      <button class="btn btn-block" type="submit" :disabled="loading">{{ loading ? 'Logging in…' : 'Log in' }}</button>
      <p class="muted center"><RouterLink to="/forgot-password">Forgot password?</RouterLink></p>
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
</style>
