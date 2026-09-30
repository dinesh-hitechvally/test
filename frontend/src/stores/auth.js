import { defineStore } from 'pinia'
import * as authApi from '../api/auth'

// Hydrated synchronously so the very first render (and the router's first
// guard check) already knows whether there's a session — no boot-time
// request, no loading flash. If the token turns out to be stale/revoked,
// the first real API call that needs it gets a 401 and client.js/graphql.js
// raise 'auth:unauthenticated' (handled in main.js) to clear it and redirect.
function storedUser() {
  try {
    return JSON.parse(localStorage.getItem('auth_user') || 'null')
  } catch {
    return null
  }
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('auth_token') || null,
    user: storedUser(),
  }),
  getters: {
    isAuthenticated: (state) => !!state.token,
  },
  actions: {
    async login(credentials) {
      const { token, user } = await authApi.login(credentials)
      this.token = token
      this.user = user
    },
    async forgotPassword(email) {
      return authApi.forgotPassword(email)
    },
    async resetPassword(payload) {
      return authApi.resetPassword(payload)
    },
    async logout() {
      try {
        await authApi.logout()
      } finally {
        // Clear locally even if the request itself failed (offline, token
        // already gone) — the user asked to log out, so the app should act
        // logged out regardless of whether the server round trip succeeded.
        this.clearSession()
      }
    },
    clearSession() {
      this.token = null
      this.user = null
    },
  },
})
