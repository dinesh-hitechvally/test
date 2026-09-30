import axios from 'axios'

// Follow whatever hostname the page was actually opened with (localhost vs.
// 127.0.0.1) rather than a hardcoded value, so the API URL always matches
// where the app was actually loaded from. VITE_API_URL (if set) still wins
// when it isn't just the default localhost value, e.g. for a real deployed
// API host.
const configuredUrl = import.meta.env.VITE_API_URL
const isDefaultLocalUrl = !configuredUrl || /^https?:\/\/localhost:8000\/?$/.test(configuredUrl)
const baseURL = isDefaultLocalUrl && typeof window !== 'undefined'
  ? `${window.location.protocol}//${window.location.hostname}:8000`
  : (configuredUrl || 'http://localhost:8000')

const client = axios.create({
  baseURL: `${baseURL}/api`,
  headers: { Accept: 'application/json' },
})

// Every request carries the Sanctum bearer token from localStorage (set by
// the auth store on login, cleared on logout) — no session cookie, no CSRF.
client.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// A 401 here means the token is gone/revoked server-side (not just "this one
// call needs auth") — tell the app so it can drop the stale session and send
// the user to /login, instead of leaving the UI stuck showing "logged in".
client.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) window.dispatchEvent(new CustomEvent('auth:unauthenticated'))
    return Promise.reject(error)
  }
)

// For plain <a href> links that can't go through axios (there are none left,
// now that exports go through downloadFile() below, but graphql.js still
// needs this to reach /graphql, which sits outside the /api prefix).
export const apiBaseUrl = baseURL

/**
 * Downloads a file from an authenticated /api route (portfolio exports) —
 * plain <a href> links can't carry the Authorization header, so this fetches
 * the file as a blob (with it) and saves it via a throwaway link instead.
 * The filename comes from the server's Content-Disposition header.
 */
export async function downloadFile(path) {
  const response = await client.get(path, { responseType: 'blob' })
  const match = /filename="?([^"]+)"?/.exec(response.headers['content-disposition'] || '')
  const filename = match ? match[1] : 'download'

  const url = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

export default client
