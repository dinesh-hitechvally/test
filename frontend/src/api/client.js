import axios from 'axios'

// Follow whatever hostname the page was actually opened with (localhost vs.
// 127.0.0.1) rather than a hardcoded value — the two are different "sites"
// to the browser, so a mismatch here breaks cookie-based auth entirely, not
// just cosmetically. VITE_API_URL (if set) still wins when it isn't just the
// default localhost value, e.g. for a real deployed API host.
const configuredUrl = import.meta.env.VITE_API_URL
const isDefaultLocalUrl = !configuredUrl || /^https?:\/\/localhost:8000\/?$/.test(configuredUrl)
const baseURL = isDefaultLocalUrl && typeof window !== 'undefined'
  ? `${window.location.protocol}//${window.location.hostname}:8000`
  : (configuredUrl || 'http://localhost:8000')

const client = axios.create({
  baseURL: `${baseURL}/api`,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

export async function ensureCsrfCookie() {
  await axios.get(`${baseURL}/sanctum/csrf-cookie`, { withCredentials: true })
}

// For plain <a href> links (e.g. CSV export) that can't go through axios —
// same host-matching logic as above, exported so pages don't duplicate it.
export const apiBaseUrl = baseURL

export default client
