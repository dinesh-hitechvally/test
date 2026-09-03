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
    async register(payload) {
      await ensureCsrfCookie()
      const { data } = await client.post('/register', payload)
      this.user = data.user
    },
    async logout() {
      await client.post('/logout')
      this.user = null
    },
  },
})
