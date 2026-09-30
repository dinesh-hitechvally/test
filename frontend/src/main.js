import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import alignNumbers from './directives/alignNumbers'
import ui from './components/ui'
import { useAuthStore } from './stores/auth'

const app = createApp(App)
const pinia = createPinia()

app.use(ui)
app.directive('align-numbers', alignNumbers)
app.use(pinia)
app.use(router)

// Keeps localStorage in sync with the auth store, however it changes —
// login, logout, or Profile.vue updating the user directly after an edit.
const auth = useAuthStore()
auth.$subscribe((_mutation, state) => {
  if (state.token) {
    localStorage.setItem('auth_token', state.token)
    localStorage.setItem('auth_user', JSON.stringify(state.user))
  } else {
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
  }
})

// Raised by client.js/graphql.js on a 401 — the token is dead (expired,
// revoked, logged out elsewhere), so drop the session and send the user to
// /login instead of leaving the UI stuck showing them as logged in.
window.addEventListener('auth:unauthenticated', () => {
  auth.clearSession()
  router.push({ name: 'login' })
})

app.mount('#app')
