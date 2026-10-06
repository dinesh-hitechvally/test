import { computed, reactive, ref } from 'vue'

/**
 * What the API is doing right now, shared by the whole app:
 *
 *  - `pending`: how many requests are in flight. The top bar shows a spinner while it is above 0, so a slow
 *    or hanging API is visible instead of looking like a frozen page.
 *  - `notices`: failures worth telling the user about (the API is down, timed out or errored). A failed
 *    request never takes a page down: the page keeps showing and a small notice explains what did not load.
 *
 * Requests are counted by the HTTP client (api/client.js), which every API call goes through.
 */
const inFlight = ref(0)

export const pending = computed(() => inFlight.value)

export function requestStarted() {
  inFlight.value++
}

export function requestFinished() {
  inFlight.value = Math.max(0, inFlight.value - 1)
}

export const notices = reactive([])

let nextId = 1

/** Network failures, timeouts and server errors are "the API is not working"; 401/404/422 are normal answers pages handle themselves. */
export function isApiOutage(error) {
  if (!error) return false
  if (error.code === 'ERR_CANCELED') return false

  const status = error.response?.status

  if (status === undefined) return Boolean(error.request || error.code || error.isAxiosError) // no response at all
  return status >= 500
}

function describe(error) {
  if (error.code === 'ECONNABORTED') return 'The server took too long to respond.'
  if (error.response === undefined) return 'Could not reach the server.'

  return error.response.data?.message || 'The server returned an error.'
}

/**
 * Tells the user something did not load, without breaking anything. Only API outages are shown (see
 * isApiOutage); repeats of the same message collapse into one notice, and it clears itself after a while.
 */
export function reportApiError(error) {
  if (!isApiOutage(error)) return

  const message = describe(error)

  if (notices.some((n) => n.message === message)) return

  const id = nextId++
  notices.push({ id, message })
  setTimeout(() => dismissNotice(id), 12000)
}

export function dismissNotice(id) {
  const index = notices.findIndex((n) => n.id === id)
  if (index !== -1) notices.splice(index, 1)
}
