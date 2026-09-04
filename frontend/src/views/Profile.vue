<script setup>
import { ref } from 'vue'
import client from '../api/client'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()

const name = ref(auth.user?.name || '')
const email = ref(auth.user?.email || '')
const profileSaving = ref(false)
const profileMessage = ref('')
const profileError = ref('')

const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirm = ref('')
const passwordSaving = ref(false)
const passwordMessage = ref('')
const passwordError = ref('')

async function saveProfile() {
  profileSaving.value = true
  profileMessage.value = ''
  profileError.value = ''
  try {
    const { data } = await client.put('/user/profile', { name: name.value, email: email.value })
    auth.user = data.user
    profileMessage.value = 'Profile updated.'
  } catch (e) {
    profileError.value = e.response?.data?.message || 'Could not update profile.'
  } finally {
    profileSaving.value = false
  }
}

async function savePassword() {
  passwordSaving.value = true
  passwordMessage.value = ''
  passwordError.value = ''
  try {
    await client.put('/user/password', {
      current_password: currentPassword.value,
      password: newPassword.value,
      password_confirmation: newPasswordConfirm.value,
    })
    passwordMessage.value = 'Password changed.'
    currentPassword.value = ''
    newPassword.value = ''
    newPasswordConfirm.value = ''
  } catch (e) {
    passwordError.value = e.response?.data?.message || Object.values(e.response?.data?.errors || {}).flat()[0] || 'Could not change password.'
  } finally {
    passwordSaving.value = false
  }
}
</script>

<template>
  <div>
    <h1>Profile</h1>

    <div class="card form-stack" style="margin-top: 16px">
      <h3>Account details</h3>
      <input v-model="name" class="input" placeholder="Name" />
      <input v-model="email" type="email" class="input" placeholder="Email" />
      <p v-if="profileMessage" class="muted">{{ profileMessage }}</p>
      <p v-if="profileError" class="error-text">{{ profileError }}</p>
      <button class="btn" :disabled="profileSaving" @click="saveProfile" style="align-self: flex-start">
        {{ profileSaving ? 'Saving…' : 'Save' }}
      </button>
    </div>

    <div class="card form-stack" style="margin-top: 16px">
      <h3>Change password</h3>
      <input v-model="currentPassword" type="password" class="input" placeholder="Current password" />
      <input v-model="newPassword" type="password" class="input" placeholder="New password" />
      <input v-model="newPasswordConfirm" type="password" class="input" placeholder="Confirm new password" />
      <p v-if="passwordMessage" class="muted">{{ passwordMessage }}</p>
      <p v-if="passwordError" class="error-text">{{ passwordError }}</p>
      <button class="btn" :disabled="passwordSaving" @click="savePassword" style="align-self: flex-start">
        {{ passwordSaving ? 'Saving…' : 'Change Password' }}
      </button>
    </div>
  </div>
</template>
