const STORAGE_KEY = 'notification_prefs'

const DEFAULTS = {
  showStopLossAlerts: true,
  showTargetAlerts: true,
  pollIntervalSeconds: 90,
}

export function getNotificationPrefs() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? { ...DEFAULTS, ...JSON.parse(raw) } : { ...DEFAULTS }
  } catch {
    return { ...DEFAULTS }
  }
}

export function setNotificationPrefs(prefs) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs))
  } catch {
    // localStorage unavailable (private mode, etc.) — preferences just won't persist
  }
}
