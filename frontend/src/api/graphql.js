import client, { apiBaseUrl } from './client'

// Runs one GraphQL operation against /graphql (bearer-token auth — see
// client.js) and returns its `data`.
//
// Errors are rethrown in the shape axios errors have, so pages keep reading
// err.response.status / err.response.data.message / err.response.data.errors
// (Laravel's per-field validation messages) exactly as before.
export async function gql(query, variables = {}) {
  const { data: body } = await client.post('/graphql', { query, variables }, { baseURL: apiBaseUrl })
  if (body.errors?.length) {
    throw toApiError(body.errors[0])
  }
  return body.data
}

function toApiError(error) {
  const ext = error.extensions ?? {}
  const err = new Error(error.message)
  err.response = {
    status: ext.status ?? 500,
    data: { message: error.message, ...(ext.validation ? { errors: ext.validation } : {}) },
  }
  // A GraphQL error resolves as a 200 at the HTTP layer, so client.js's response
  // interceptor never sees the 401 — raise the same signal here instead.
  if (err.response.status === 401) window.dispatchEvent(new CustomEvent('auth:unauthenticated'))
  return err
}

// Form values → a GraphQL Float/Int: blank → null (the server's validation
// then reports "required"), anything else → a number.
export function num(value) {
  return value === '' || value === null || value === undefined ? null : Number(value)
}
