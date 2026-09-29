import { defineStore } from 'pinia'
import { ensureCsrfCookie } from '../api/client'
import * as authApi from '../api/auth'

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
        this.user = await authApi.me()
      } catch {
        this.user = null
      } finally {
        this.checked = true
      }
    },
    async login(credentials) {
      await ensureCsrfCookie()
      await authApi.login(credentials)
      await this.fetchUser()
    },
    async forgotPassword(email) {
      await ensureCsrfCookie()
      return authApi.forgotPassword(email)
    },
    async resetPassword(payload) {
      await ensureCsrfCookie()
      return authApi.resetPassword(payload)
    },
    async logout() {
      await authApi.logout()
      this.user = null
    },
  },
})
