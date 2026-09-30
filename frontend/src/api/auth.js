import { gql } from './graphql'
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

export async function updateProfile({ name, email }) {
  return (await gql(`mutation ($name: String, $email: String) { updateProfile(name: $name, email: $email) { ${USER} } }`,
    { name, email })).updateProfile
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
