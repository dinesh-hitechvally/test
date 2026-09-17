<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import AuthLayout from '../components/AuthLayout.vue'
import NavIcon from '../components/NavIcon.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref(route.query.email || '')
const password = ref('')
const passwordConfirmation = ref('')
const showPassword = ref(false)
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
  <AuthLayout title="Reset password">
    <form class="form-stack" @submit.prevent="handleSubmit">
      <p v-if="!route.query.token" class="error-text">This link is missing its reset token — use the link from your email.</p>
      <label>
        Email
        <span class="input-icon-wrap">
          <span class="input-icon"><NavIcon name="mail" /></span>
          <input v-model="email" type="email" class="input" required autocomplete="email" />
        </span>
      </label>
      <label>
        New password
        <span class="input-icon-wrap">
          <span class="input-icon"><NavIcon name="lock" /></span>
          <input v-model="password" :type="showPassword ? 'text' : 'password'" class="input" required autofocus autocomplete="new-password" />
          <button type="button" class="input-toggle" tabindex="-1" @click="showPassword = !showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'">
            <NavIcon :name="showPassword ? 'eye-off' : 'eye'" />
          </button>
        </span>
      </label>
      <label>
        Confirm new password
        <span class="input-icon-wrap">
          <span class="input-icon"><NavIcon name="lock" /></span>
          <input v-model="passwordConfirmation" :type="showPassword ? 'text' : 'password'" class="input" required autocomplete="new-password" />
        </span>
      </label>
      <p v-if="message" class="muted">{{ message }} Redirecting to log in…</p>
      <p v-if="error" class="error-text">{{ error }}</p>
      <button class="btn btn-block" type="submit" :disabled="loading || !route.query.token">{{ loading ? 'Resetting…' : 'Reset password' }}</button>
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
