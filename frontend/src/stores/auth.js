import { defineStore } from 'pinia'
import client, { ensureCsrfCookie } from '../api/client'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    checked: false,
  }),
  getters: {
    isAuthenticated: (state) => !!state.user,
  },
  actions: {
    async fetchUser() {
      try {
        const { data } = await client.get('/user')
        this.user = data.user
      } catch {
        this.user = null
      } finally {
        this.checked = true
      }
    },
    async login(credentials) {
      await ensureCsrfCookie()
      await client.post('/login', credentials)
      await this.fetchUser()
    },
    async forgotPassword(email) {
      await ensureCsrfCookie()
      const { data } = await client.post('/forgot-password', { email })
      return data.message
    },
    async resetPassword(payload) {
      await ensureCsrfCookie()
      const { data } = await client.post('/reset-password', payload)
      return data.message
    },
    async logout() {
      await client.post('/logout')
      this.user = null
    },
  },
})
