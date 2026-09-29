import client, { apiBaseUrl, ensureCsrfCookie } from './client'

// Runs one GraphQL operation against /graphql (same cookie session as the
// REST client) and returns its `data`.
//
// Errors are rethrown in the shape axios errors have, so pages keep reading
// err.response.status / err.response.data.message / err.response.data.errors
// (Laravel's per-field validation messages) exactly as before.
export async function gql(query, variables = {}) {
  const { data: body } = await post({ query, variables })
  if (body.errors?.length) {
    throw toApiError(body.errors[0])
  }
  return body.data
}

// Every operation is a POST, so each one needs the XSRF-TOKEN cookie: fetch
// it first when it's missing, and once more if it expired (419).
async function post(payload) {
  if (!document.cookie.includes('XSRF-TOKEN=')) await ensureCsrfCookie()
  try {
    return await client.post('/graphql', payload, { baseURL: apiBaseUrl })
  } catch (err) {
    if (err.response?.status !== 419) throw err
    await ensureCsrfCookie()
    return client.post('/graphql', payload, { baseURL: apiBaseUrl })
  }
}

function toApiError(error) {
  const ext = error.extensions ?? {}
  const err = new Error(error.message)
  err.response = {
    status: ext.status ?? 500,
    data: { message: error.message, ...(ext.validation ? { errors: ext.validation } : {}) },
  }
  return err
}

// Form values → a GraphQL Float/Int: blank → null (the server's validation
// then reports "required"), anything else → a number.
export function num(value) {
  return value === '' || value === null || value === undefined ? null : Number(value)
}
