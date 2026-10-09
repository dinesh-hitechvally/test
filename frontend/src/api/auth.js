import { gql } from './graphql'
import client from './client'
import { USER } from './fields'

export async function me() {
  return (await gql(`{ me { ${USER} } }`)).me
}

/** @returns { token, token_type, user } */
export async function login({ email, password }) {
  return (await gql(`mutation ($email: String, $password: String) {
    login(email: $email, password: $password) { token token_type user { ${USER} } }
  }`, { email, password })).login
}

export async function logout() {
  return (await gql('mutation { logout }')).logout
}

/** @returns the confirmation message */
export async function forgotPassword(email) {
  return (await gql('mutation ($email: String) { forgotPassword(email: $email) }', { email })).forgotPassword
}

/** @returns the confirmation message */
export async function resetPassword({ token, email, password, password_confirmation }) {
  return (await gql(`mutation ($token: String, $email: String, $password: String, $password_confirmation: String) {
    resetPassword(token: $token, email: $email, password: $password, password_confirmation: $password_confirmation)
  }`, { token, email, password, password_confirmation })).resetPassword
}

/** The Profile page: counts of what the account holds, active sessions and the last five logins. */
export async function accountSummary() {
  return (await gql(`{ accountSummary {
    portfolios transactions watchlists watchlist_stocks saved_screens active_sessions total_logins
    recent_logins { id ip_address user_agent city region country logged_in_at }
  } }`)).accountSummary
}

/** Signs out every other device; resolves to how many were signed out. */
export async function logoutOtherSessions() {
  return (await gql('mutation { logoutOtherSessions }')).logoutOtherSessions
}

const PROFILE_FIELDS = ['first_name', 'last_name', 'email', 'username', 'phone', 'gender', 'date_of_birth', 'occupation', 'bio', 'timezone', 'country', 'province', 'city', 'street_address', 'postal_code']

/** Saves the profile form (every field of it); an empty value clears an optional field. Resolves to the updated user. */
export async function updateProfile(form) {
  const variables = Object.fromEntries(PROFILE_FIELDS.map((key) => [key, form[key] === '' ? null : form[key]]))
  const declared = PROFILE_FIELDS.map((key) => `$${key}: String`).join(', ')
  const passed = PROFILE_FIELDS.map((key) => `${key}: $${key}`).join(', ')

  return (await gql(`mutation (${declared}) { updateProfile(${passed}) { ${USER} } }`, variables)).updateProfile
}

/** Uploads a profile picture (cropped to a square by the server); resolves to its address. */
export async function uploadAvatar(file) {
  const body = new FormData()
  body.append('avatar', file)

  const { data } = await client.post('/profile/avatar', body)
  return data.avatar_url
}

export async function removeAvatar() {
  await client.delete('/profile/avatar')
}

/** @returns the confirmation message */
export async function updatePassword({ current_password, password, password_confirmation }) {
  return (await gql(`mutation ($current_password: String, $password: String, $password_confirmation: String) {
    updatePassword(current_password: $current_password, password: $password, password_confirmation: $password_confirmation)
  }`, { current_password, password, password_confirmation })).updatePassword
}

export async function loginHistory() {
  return (await gql('{ loginHistory { id ip_address user_agent city region country logged_in_at } }')).loginHistory
}

export async function users() {
  return (await gql('{ users { id name email created_at } }')).users
}

const SIGNAL_SETTINGS = `weights { category weight } min_pct margin guard_extremes is_custom
  defaults { weights { category weight } min_pct margin guard_extremes }`

/** The person's own signal settings (or the system defaults while they have none). */
export async function signalSettings() {
  return (await gql(`{ signalSettings { ${SIGNAL_SETTINGS} } }`)).signalSettings
}

/** @param {{ weights: Record<string, number>, min_pct: number, margin: number, guard_extremes: boolean }} form */
export async function updateSignalSettings(form) {
  return (await gql(`mutation ($weights: SignalWeightsInput!, $min_pct: Int!, $margin: Int!, $guard_extremes: Boolean!) {
    updateSignalSettings(weights: $weights, min_pct: $min_pct, margin: $margin, guard_extremes: $guard_extremes) { ${SIGNAL_SETTINGS} }
  }`, form)).updateSignalSettings
}

export async function resetSignalSettings() {
  return (await gql(`mutation { resetSignalSettings { ${SIGNAL_SETTINGS} } }`)).resetSignalSettings
}
