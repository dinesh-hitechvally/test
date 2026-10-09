<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import * as authApi from '../api/auth'
import { useAuthStore } from '../stores/auth'
import { deviceLabel, locationLabel } from '../utils/device'
import { formatDateTime } from '../utils/format'
import { GENDERS, NEPAL_PROVINCES, countryOptions, timezoneOptions } from '../utils/places'
import { getNotificationPrefs, setNotificationPrefs } from '../utils/notificationPrefs'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

// ---- tabs ------------------------------------------------------------------------------------------------------
const TABS = [
  { key: 'personal', label: 'Personal' },
  { key: 'address', label: 'Address' },
  { key: 'account', label: 'Account' },
  { key: 'security', label: 'Security' },
  { key: 'preferences', label: 'Preferences' },
]
const tab = ref(TABS.some((t) => t.key === route.query.tab) ? route.query.tab : 'personal')
watch(tab, (key) => router.replace({ query: key === 'personal' ? {} : { tab: key } }))

// ---- the profile form (Personal and Address tabs share one Save) ------------------------------------------------
const FIELDS = ['first_name', 'last_name', 'email', 'username', 'phone', 'gender', 'date_of_birth', 'occupation', 'timezone', 'bio', 'country', 'province', 'city', 'street_address', 'postal_code']
const form = reactive(Object.fromEntries(FIELDS.map((k) => [k, ''])))
const saved = ref({})

function fillForm() {
  const user = auth.user || {}
  const profile = user.profile || {}
  const values = { email: user.email, username: user.username, ...profile }
  FIELDS.forEach((key) => (form[key] = values[key] ?? ''))
  saved.value = { ...form }
}
fillForm()

const dirty = computed(() => FIELDS.some((k) => (form[k] ?? '') !== (saved.value[k] ?? '')))
const personalDirty = computed(() => ['first_name', 'last_name', 'email', 'username', 'phone', 'gender', 'date_of_birth', 'occupation', 'timezone', 'bio'].some((k) => form[k] !== saved.value[k]))
const addressDirty = computed(() => ['country', 'province', 'city', 'street_address', 'postal_code'].some((k) => form[k] !== saved.value[k]))

const saving = ref(false)
const message = ref('')
const error = ref('')
const fieldErrors = ref({})
const firstError = (key) => fieldErrors.value[key]?.[0] || ''

let messageTimer = null
function flash(target, text) {
  target.value = text
  clearTimeout(messageTimer)
  messageTimer = setTimeout(() => (target.value = ''), 5000)
}

async function saveProfile() {
  saving.value = true
  message.value = ''
  error.value = ''
  fieldErrors.value = {}
  try {
    auth.user = await authApi.updateProfile({ ...form, first_name: form.first_name.trim(), email: form.email.trim() })
    fillForm()
    flash(message, 'Profile saved.')
  } catch (e) {
    fieldErrors.value = e.response?.data?.errors || {}
    error.value = Object.keys(fieldErrors.value).length ? 'Please fix the highlighted fields.' : e.response?.data?.message || 'Could not save your profile.'
    // Take the person to the tab that holds the first problem.
    const addressFields = ['country', 'province', 'city', 'street_address', 'postal_code']
    const bad = Object.keys(fieldErrors.value)
    if (bad.length && bad.every((k) => addressFields.includes(k))) tab.value = 'address'
    else if (bad.length) tab.value = 'personal'
  } finally {
    saving.value = false
  }
}

function discard() {
  fillForm()
  fieldErrors.value = {}
  error.value = ''
}

onBeforeRouteLeave(() => !dirty.value || window.confirm('You have unsaved changes. Leave without saving?'))

const countries = countryOptions()
const timezones = timezoneOptions()
const isNepal = computed(() => form.country === 'Nepal')
const provinceOptions = computed(() => [{ value: '', label: 'Select' }, ...NEPAL_PROVINCES.map((p) => ({ value: p, label: p }))])

// ---- the picture -----------------------------------------------------------------------------------------------
const fileInput = ref(null)
const avatarBusy = ref(false)
const avatarMessage = ref('')
const avatarUrl = computed(() => auth.user?.profile?.avatar_url || null)

const initials = computed(() => {
  const parts = (auth.user?.name || auth.user?.email || '?').trim().split(/\s+/)
  return ((parts[0]?.[0] || '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase() || '?'
})

function setAvatar(url) {
  auth.user = { ...auth.user, profile: { ...(auth.user?.profile || {}), avatar_url: url } }
}

async function onPicture(event) {
  const file = event.target.files?.[0]
  event.target.value = '' // so choosing the same file again still fires
  if (!file) return

  if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return flash(avatarMessage, 'Choose a JPG, PNG or WebP picture.')
  if (file.size > 3 * 1024 * 1024) return flash(avatarMessage, 'That picture is over 3 MB. Choose a smaller one.')

  avatarBusy.value = true
  try {
    setAvatar(await authApi.uploadAvatar(file))
    flash(avatarMessage, 'Profile picture updated.')
  } catch (e) {
    flash(avatarMessage, e.response?.data?.errors?.avatar?.[0] || e.response?.data?.message || 'Could not upload the picture.')
  } finally {
    avatarBusy.value = false
  }
}

async function removePicture() {
  avatarBusy.value = true
  try {
    await authApi.removeAvatar()
    setAvatar(null)
    flash(avatarMessage, 'Profile picture removed.')
  } catch (e) {
    flash(avatarMessage, e.response?.data?.message || 'Could not remove the picture.')
  } finally {
    avatarBusy.value = false
  }
}

// ---- account information (read-only) -----------------------------------------------------------------------------
const zone = computed(() => form.timezone || saved.value.timezone || '')
const when = (value) => formatDateTime(value, zone.value)
const verified = computed(() => !!auth.user?.email_verified_at)
const memberSince = computed(() => (auth.user?.created_at ? new Date(auth.user.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric', ...(zone.value ? { timeZone: zone.value } : {}) }) : null))

const accountRows = computed(() => [
  { label: 'User ID', value: auth.user?.user_code || '—' },
  { label: 'Username', value: auth.user?.username ? `@${auth.user.username}` : 'Not set' },
  { label: 'Account status', badge: { text: 'Active', tone: 'buy' } },
  { label: 'Email address', value: auth.user?.email },
  { label: 'Email verified', badge: verified.value ? { text: `Verified ${formatDateTime(auth.user.email_verified_at, zone.value)}`, tone: 'buy' } : { text: 'Not verified', tone: 'hold' } },
  { label: 'Registered', value: auth.user?.created_at ? when(auth.user.created_at) : '—' },
  { label: 'Last login', value: auth.user?.last_login_at ? when(auth.user.last_login_at) : '—' },
  { label: 'Password last changed', value: auth.user?.password_changed_at ? when(auth.user.password_changed_at) : 'Never changed since the account was created' },
])

// ---- password --------------------------------------------------------------------------------------------------
const currentPassword = ref('')
const newPassword = ref('')
const newPasswordConfirm = ref('')
const showPasswords = ref(false)
const passwordSaving = ref(false)
const passwordMessage = ref('')
const passwordError = ref('')

const MIN_PASSWORD = 8
const tooShort = computed(() => newPassword.value !== '' && newPassword.value.length < MIN_PASSWORD)
const mismatch = computed(() => newPasswordConfirm.value !== '' && newPassword.value !== newPasswordConfirm.value)
const canChangePassword = computed(() => currentPassword.value !== '' && newPassword.value.length >= MIN_PASSWORD && newPassword.value === newPasswordConfirm.value)

async function savePassword() {
  passwordSaving.value = true
  passwordMessage.value = ''
  passwordError.value = ''
  try {
    await authApi.updatePassword({ current_password: currentPassword.value, password: newPassword.value, password_confirmation: newPasswordConfirm.value })
    auth.user = { ...auth.user, password_changed_at: new Date().toISOString() }
    flash(passwordMessage, 'Password changed.')
    currentPassword.value = ''
    newPassword.value = ''
    newPasswordConfirm.value = ''
  } catch (e) {
    passwordError.value = Object.values(e.response?.data?.errors || {}).flat()[0] || e.response?.data?.message || 'Could not change your password.'
  } finally {
    passwordSaving.value = false
  }
}

// ---- sessions and sign-ins (loaded separately: the forms work even if this fails) -----------------------------
const summary = ref(null)
const summaryLoading = ref(true)
const summaryError = ref(false)
const sessionMessage = ref('')
const sessionBusy = ref(false)

async function loadSummary() {
  summaryLoading.value = true
  summaryError.value = false
  try {
    summary.value = await authApi.accountSummary()
  } catch {
    summaryError.value = true
  } finally {
    summaryLoading.value = false
  }
}

async function signOutOthers() {
  if (!window.confirm('Sign out of every other device? This device stays signed in.')) return
  sessionBusy.value = true
  sessionMessage.value = ''
  try {
    const ended = await authApi.logoutOtherSessions()
    flash(sessionMessage, ended === 1 ? 'Signed out 1 other device.' : `Signed out ${ended} other devices.`)
    await loadSummary()
  } catch (e) {
    flash(sessionMessage, e.response?.data?.message || 'Could not sign the other devices out.')
  } finally {
    sessionBusy.value = false
  }
}

const stats = computed(() =>
  summary.value
    ? [
        { label: 'Portfolios', value: summary.value.portfolios, sub: `${summary.value.transactions} transaction${summary.value.transactions === 1 ? '' : 's'}`, to: '/portfolio' },
        { label: 'Watchlists', value: summary.value.watchlists, sub: `${summary.value.watchlist_stocks} stock${summary.value.watchlist_stocks === 1 ? '' : 's'} watched`, to: '/watchlist' },
        { label: 'Saved screens', value: summary.value.saved_screens, sub: 'in the screener', to: '/screener/saved' },
        { label: 'Logins', value: summary.value.total_logins, sub: 'recorded', to: '/settings/login-history' },
      ]
    : []
)

// ---- alert preferences (kept in this browser) ------------------------------------------------------------------
const prefs = ref(getNotificationPrefs())
const prefsMessage = ref('')
const POLL_OPTIONS = [
  { value: 30, label: '30 seconds' },
  { value: 60, label: '1 minute' },
  { value: 90, label: '90 seconds' },
  { value: 300, label: '5 minutes' },
]

function savePrefs() {
  setNotificationPrefs(prefs.value)
  flash(prefsMessage, 'Saved — takes effect on the next page load.')
}

onMounted(async () => {
  loadSummary()
  // The stored copy of the account can be from an older version of the app: take the current one.
  try {
    const fresh = await authApi.me()
    if (!dirty.value) {
      auth.user = fresh
      fillForm()
    } else {
      auth.user = fresh
    }
  } catch {
    // the page works from what is stored
  }
})
onBeforeUnmount(() => clearTimeout(messageTimer))
</script>

<template>
  <div class="profile">
    <!-- Who -->
    <Card class="identity">
      <div class="avatar-wrap">
        <div class="avatar" :class="{ busy: avatarBusy }">
          <img v-if="avatarUrl" :src="avatarUrl" alt="Profile picture" />
          <span v-else aria-hidden="true">{{ initials }}</span>
        </div>
        <div class="avatar-actions">
          <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" hidden @change="onPicture" />
          <button class="btn-secondary btn small-btn" type="button" :disabled="avatarBusy" @click="fileInput.click()">{{ avatarUrl ? 'Change photo' : 'Upload photo' }}</button>
          <button v-if="avatarUrl" class="link-btn small" type="button" :disabled="avatarBusy" @click="removePicture">Remove</button>
        </div>
      </div>

      <div class="who">
        <h2>{{ auth.user?.name }}</h2>
        <p class="muted">
          {{ auth.user?.email }}
          <span v-if="auth.user?.username"> · @{{ auth.user.username }}</span>
        </p>
        <p class="badges">
          <span class="badge buy">Active</span>
          <span class="badge" :class="verified ? 'buy' : 'hold'">{{ verified ? 'Email verified' : 'Email not verified' }}</span>
          <span v-if="auth.user?.user_code" class="muted small">{{ auth.user.user_code }}</span>
          <span v-if="memberSince" class="muted small">Member since {{ memberSince }}</span>
        </p>
        <p v-if="avatarMessage" class="avatar-msg small">{{ avatarMessage }}</p>
      </div>

      <div v-if="stats.length" class="stats">
        <RouterLink v-for="s in stats" :key="s.label" :to="s.to" class="stat">
          <span class="stat-value">{{ s.value }}</span>
          <span class="stat-label">{{ s.label }}</span>
          <span class="stat-sub muted">{{ s.sub }}</span>
        </RouterLink>
      </div>
    </Card>

    <Card class="tabbed">
      <div class="tabs" role="tablist">
        <button v-for="t in TABS" :key="t.key" type="button" role="tab" class="tab" :class="{ on: tab === t.key }" :aria-selected="tab === t.key" @click="tab = t.key">
          {{ t.label }}
          <span v-if="(t.key === 'personal' && personalDirty) || (t.key === 'address' && addressDirty)" class="dot" title="Unsaved changes"></span>
        </button>
      </div>

      <!-- Personal + Address: one form, one Save -->
      <form v-show="tab === 'personal' || tab === 'address'" class="panel" @submit.prevent="saveProfile">
        <div v-show="tab === 'personal'" class="grid2">
          <div class="field">
            <label for="f-first">First name</label>
            <input id="f-first" v-model="form.first_name" class="input" :class="{ bad: firstError('first_name') }" autocomplete="given-name" maxlength="60" required />
            <span v-if="firstError('first_name')" class="msg error-text small">{{ firstError('first_name') }}</span>
          </div>
          <div class="field">
            <label for="f-last">Last name</label>
            <input id="f-last" v-model="form.last_name" class="input" :class="{ bad: firstError('last_name') }" autocomplete="family-name" maxlength="60" />
            <span v-if="firstError('last_name')" class="msg error-text small">{{ firstError('last_name') }}</span>
          </div>
          <div class="field">
            <label for="f-email">Email address</label>
            <input id="f-email" v-model="form.email" type="email" class="input" :class="{ bad: firstError('email') }" autocomplete="email" maxlength="255" required />
            <span v-if="firstError('email')" class="msg error-text small">{{ firstError('email') }}</span>
            <span v-else class="msg muted small">Used to sign in and for password reset emails.</span>
          </div>
          <div class="field">
            <label for="f-username">Username</label>
            <input id="f-username" v-model="form.username" class="input" :class="{ bad: firstError('username') }" autocomplete="username" maxlength="40" placeholder="e.g. dineshghimire" />
            <span v-if="firstError('username')" class="msg error-text small">{{ firstError('username') }}</span>
          </div>
          <div class="field">
            <label for="f-phone">Phone number</label>
            <input id="f-phone" v-model="form.phone" type="tel" class="input" :class="{ bad: firstError('phone') }" autocomplete="tel" maxlength="30" placeholder="+977 98XXXXXXXX" />
            <span v-if="firstError('phone')" class="msg error-text small">{{ firstError('phone') }}</span>
          </div>
          <div class="field">
            <label>Gender</label>
            <SearchableSelect v-model="form.gender" :options="GENDERS" />
            <span v-if="firstError('gender')" class="msg error-text small">{{ firstError('gender') }}</span>
          </div>
          <div class="field">
            <label for="f-dob">Date of birth</label>
            <input id="f-dob" v-model="form.date_of_birth" type="date" class="input" :class="{ bad: firstError('date_of_birth') }" :max="new Date().toISOString().slice(0, 10)" autocomplete="bday" />
            <span v-if="firstError('date_of_birth')" class="msg error-text small">{{ firstError('date_of_birth') }}</span>
          </div>
          <div class="field">
            <label for="f-occupation">Occupation</label>
            <input id="f-occupation" v-model="form.occupation" class="input" maxlength="80" placeholder="e.g. Software Developer" />
          </div>
          <div class="field">
            <label>Time zone</label>
            <SearchableSelect v-model="form.timezone" :options="timezones" />
            <span v-if="firstError('timezone')" class="msg error-text small">{{ firstError('timezone') }}</span>
            <span v-else class="msg muted small">Dates and times on this page are shown in it. With none selected, this browser's time zone is used.</span>
          </div>
          <div class="field wide">
            <label for="f-bio">Bio</label>
            <textarea id="f-bio" v-model="form.bio" class="input" rows="3" maxlength="500" placeholder="A line or two about you"></textarea>
            <span class="msg muted small counter">{{ form.bio.length }} / 500</span>
          </div>
        </div>

        <div v-show="tab === 'address'" class="grid2">
          <div class="field">
            <label>Country</label>
            <SearchableSelect v-model="form.country" :options="countries" />
          </div>
          <div class="field">
            <label for="f-province">Province / state</label>
            <SearchableSelect v-if="isNepal" v-model="form.province" :options="provinceOptions" />
            <input v-else id="f-province" v-model="form.province" class="input" maxlength="80" autocomplete="address-level1" />
          </div>
          <div class="field">
            <label for="f-city">City</label>
            <input id="f-city" v-model="form.city" class="input" maxlength="80" autocomplete="address-level2" />
          </div>
          <div class="field">
            <label for="f-postal">Postal code</label>
            <input id="f-postal" v-model="form.postal_code" class="input" maxlength="20" autocomplete="postal-code" />
          </div>
          <div class="field wide">
            <label for="f-street">Street address</label>
            <input id="f-street" v-model="form.street_address" class="input" maxlength="150" autocomplete="street-address" />
          </div>
        </div>

        <div class="savebar">
          <button class="btn" type="submit" :disabled="saving || !dirty || !form.first_name.trim() || !form.email.trim()">{{ saving ? 'Saving…' : 'Save changes' }}</button>
          <button v-if="dirty" class="btn-secondary btn" type="button" :disabled="saving" @click="discard">Discard</button>
          <span v-if="message" class="ok">{{ message }}</span>
          <span v-if="error" class="error-text">{{ error }}</span>
          <span v-else-if="dirty" class="muted small">You have unsaved changes.</span>
        </div>
      </form>

      <!-- Account: read-only -->
      <div v-show="tab === 'account'" class="panel">
        <dl class="facts">
          <template v-for="row in accountRows" :key="row.label">
            <dt>{{ row.label }}</dt>
            <dd>
              <span v-if="row.badge" class="badge" :class="row.badge.tone">{{ row.badge.text }}</span>
              <template v-else>{{ row.value }}</template>
            </dd>
          </template>
        </dl>
        <p class="muted small">These are set by the system and cannot be edited here. Change your name, email or username on the Personal tab.</p>
      </div>

      <!-- Security -->
      <div v-show="tab === 'security'" class="panel security">
        <div class="sec-col">
          <h4 class="subhead">Change password</h4>
          <p class="muted small">
            {{ auth.user?.password_changed_at ? `Last changed ${when(auth.user.password_changed_at)}.` : 'Never changed since the account was created.' }}
          </p>
          <form class="form" @submit.prevent="savePassword">
            <input type="text" :value="auth.user?.email" autocomplete="username" hidden aria-hidden="true" tabindex="-1" />
            <div class="field">
              <label for="p-current">Current password</label>
              <input id="p-current" v-model="currentPassword" :type="showPasswords ? 'text' : 'password'" class="input" autocomplete="current-password" />
            </div>
            <div class="field">
              <label for="p-new">New password</label>
              <input id="p-new" v-model="newPassword" :type="showPasswords ? 'text' : 'password'" class="input" autocomplete="new-password" />
              <span class="msg small" :class="tooShort ? 'error-text' : 'muted'">At least {{ MIN_PASSWORD }} characters.</span>
            </div>
            <div class="field">
              <label for="p-confirm">Confirm new password</label>
              <input id="p-confirm" v-model="newPasswordConfirm" :type="showPasswords ? 'text' : 'password'" class="input" autocomplete="new-password" />
              <span v-if="mismatch" class="msg small error-text">The two passwords do not match.</span>
            </div>
            <label class="check small muted"><input v-model="showPasswords" type="checkbox" /> Show passwords</label>
            <p v-if="passwordMessage" class="ok">{{ passwordMessage }}</p>
            <p v-if="passwordError" class="error-text">{{ passwordError }}</p>
            <div><button class="btn" type="submit" :disabled="passwordSaving || !canChangePassword">{{ passwordSaving ? 'Saving…' : 'Change password' }}</button></div>
          </form>
        </div>

        <div class="sec-col">
          <h4 class="subhead">Signed-in devices</h4>
          <LoadingState v-if="summaryLoading && !summary" />
          <div v-else-if="summaryError" class="retry">
            <p class="muted">Could not load your sign-in details.</p>
            <button class="btn-secondary btn" @click="loadSummary">Try again</button>
          </div>
          <template v-else-if="summary">
            <div class="session-row">
              <div>
                <strong>{{ summary.active_sessions }} signed-in device{{ summary.active_sessions === 1 ? '' : 's' }}</strong>
                <p class="muted small">This device is one of them. Sign out the others if you used a shared or lost device.</p>
              </div>
              <button class="btn-secondary btn" :disabled="sessionBusy || summary.active_sessions <= 1" @click="signOutOthers">{{ sessionBusy ? 'Signing out…' : 'Sign out other devices' }}</button>
            </div>
            <p v-if="sessionMessage" class="ok">{{ sessionMessage }}</p>

            <h4 class="subhead logins">Recent sign-ins</h4>
            <table v-if="summary.recent_logins.length" v-align-numbers class="table compact">
              <thead><tr><th>When</th><th>Device</th><th>Where</th></tr></thead>
              <tbody>
                <tr v-for="(row, i) in summary.recent_logins" :key="row.id">
                  <td>{{ when(row.logged_in_at) }} <span v-if="i === 0" class="badge buy latest">Latest</span></td>
                  <td class="muted">{{ deviceLabel(row.user_agent) }}</td>
                  <td class="muted">{{ locationLabel(row) }}<span v-if="row.ip_address" class="small"> · {{ row.ip_address }}</span></td>
                </tr>
              </tbody>
            </table>
            <p v-else class="muted small">No sign-ins recorded yet.</p>
            <RouterLink to="/settings/login-history" class="more">View full login history →</RouterLink>
          </template>
        </div>
      </div>

      <!-- Preferences -->
      <div v-show="tab === 'preferences'" class="panel">
        <h4 class="subhead">Price alerts</h4>
        <p class="muted small">Which alerts the bell in the top bar shows. These are kept in this browser.</p>
        <div class="prefs">
          <label class="toggle"><input v-model="prefs.showStopLossAlerts" type="checkbox" /> Show stop-loss-hit alerts</label>
          <label class="toggle"><input v-model="prefs.showTargetAlerts" type="checkbox" /> Show target-hit alerts</label>
          <label class="toggle">
            Check for new alerts every
            <SearchableSelect v-model="prefs.pollIntervalSeconds" :options="POLL_OPTIONS" style="width: auto; min-width: 140px" />
          </label>
        </div>
        <div class="savebar">
          <button class="btn" type="button" @click="savePrefs">Save preferences</button>
          <span v-if="prefsMessage" class="ok">{{ prefsMessage }}</span>
        </div>
        <p class="muted small more-links">
          Time zone is set on the <a href="#" @click.prevent="tab = 'personal'">Personal</a> tab.
          Where the market data comes from is on the <RouterLink to="/settings/data-source">data source settings</RouterLink> page.
        </p>
      </div>
    </Card>
  </div>
</template>

<style scoped>
.profile {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* ---- identity ---- */
.identity {
  display: flex;
  align-items: center;
  gap: 22px;
  flex-wrap: wrap;
}

.avatar-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.avatar {
  width: 88px;
  height: 88px;
  border-radius: 50%;
  overflow: hidden;
  display: grid;
  place-items: center;
  background: var(--primary);
  color: #fff;
  font-size: 1.9rem;
  font-weight: 700;
  letter-spacing: 0.03em;
}

.avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.avatar.busy {
  opacity: 0.55;
}

.avatar-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.small-btn {
  padding: 4px 12px;
  font-size: 0.8rem;
}

.avatar-msg {
  margin: 6px 0 0;
  color: var(--text-muted);
}

.who {
  flex: 1 1 280px;
  min-width: 0;
}

.who h2 {
  margin: 0;
  font-size: 1.35rem;
}

.who p {
  margin: 2px 0 0;
}

.badges {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 8px !important;
}

.stats {
  display: flex;
  gap: 10px;
  margin-left: auto;
  flex-wrap: wrap;
}

.stat {
  display: flex;
  flex-direction: column;
  min-width: 118px;
  padding: 10px 14px;
  border: 1px solid var(--border);
  border-radius: 10px;
  text-decoration: none;
  color: inherit;
}

.stat:hover {
  border-color: var(--primary);
}

.stat-value {
  font-size: 1.4rem;
  font-weight: 700;
  line-height: 1.1;
}

.stat-label {
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--text-muted);
}

.stat-sub {
  font-size: 0.75rem;
}

/* ---- tabs ---- */
.tabs {
  display: flex;
  gap: 4px;
  margin: -4px 0 18px;
  border-bottom: 1px solid var(--border);
}

.tab {
  position: relative;
  border: 0;
  background: none;
  padding: 10px 16px;
  font: inherit;
  font-weight: 500;
  color: var(--text-muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  white-space: nowrap;
}

.tab:hover {
  color: var(--text);
}

.tab.on {
  color: var(--primary);
  border-bottom-color: var(--primary);
  font-weight: 600;
}

.dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  margin-left: 6px;
  border-radius: 50%;
  background: var(--sell);
  vertical-align: middle;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* ---- forms ---- */
.grid2 {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px 28px;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-width: 0;
}

.field.wide {
  grid-column: 1 / -1;
}

.field label {
  font-size: 0.8rem;
  font-weight: 600;
}

.input.bad {
  border-color: var(--strong-sell);
}

textarea.input {
  resize: vertical;
  font: inherit;
}

.msg {
  min-height: 1em;
}

.counter {
  text-align: right;
}

.savebar {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding-top: 14px;
  border-top: 1px solid var(--border);
}

.ok {
  margin: 0;
  color: var(--strong-buy);
  font-size: 0.88rem;
}

.form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.check,
.toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
}

.toggle {
  font-size: 0.9rem;
}

.prefs {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* ---- account facts ---- */
.facts {
  display: grid;
  grid-template-columns: 220px 1fr;
  margin: 0;
}

.facts dt,
.facts dd {
  margin: 0;
  padding: 12px 0;
  border-bottom: 1px solid var(--border);
}

.facts dt {
  font-weight: 600;
  font-size: 0.85rem;
  color: var(--text-muted);
}

/* ---- security ---- */
.security {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.5fr);
  gap: 0 32px;
}

.sec-col + .sec-col {
  padding-left: 32px;
  border-left: 1px solid var(--border);
}

.sec-col > p {
  margin: 0 0 12px;
}

.subhead {
  margin: 0 0 6px;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
}

.subhead.logins {
  margin: 18px 0 6px;
}

.session-row {
  display: flex;
  align-items: center;
  gap: 16px;
  justify-content: space-between;
  flex-wrap: wrap;
}

.session-row p {
  margin: 2px 0 0;
}

.latest {
  margin-left: 6px;
}

.more {
  display: inline-block;
  margin-top: 10px;
  font-size: 0.85rem;
}

.more-links {
  margin: 0;
}

.retry {
  display: flex;
  align-items: center;
  gap: 12px;
}

@media (max-width: 900px) {
  .tabs {
    overflow-x: auto;
  }

  .grid2,
  .security {
    grid-template-columns: 1fr;
  }

  .sec-col + .sec-col {
    margin-top: 22px;
    padding-top: 22px;
    padding-left: 0;
    border-left: 0;
    border-top: 1px solid var(--border);
  }

  .facts {
    grid-template-columns: 1fr;
  }

  .facts dt {
    border-bottom: 0;
    padding-bottom: 0;
  }

  .stats {
    margin-left: 0;
  }
}
</style>
