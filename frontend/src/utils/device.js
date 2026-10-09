// A user agent string is long and mostly noise ("Mozilla/5.0 (...) AppleWebKit/537.36 ...") —
// this pulls out just the browser/OS pair most people actually recognize.
export function deviceLabel(userAgent) {
  if (!userAgent) return 'Unknown device'

  const os = /Windows/.test(userAgent) ? 'Windows'
    : /Mac OS X/.test(userAgent) ? 'macOS'
    : /Android/.test(userAgent) ? 'Android'
    : /iPhone|iPad/.test(userAgent) ? 'iOS'
    : /Linux/.test(userAgent) ? 'Linux'
    : 'Unknown OS'

  const browser = /Edg\//.test(userAgent) ? 'Edge'
    : /Chrome\//.test(userAgent) ? 'Chrome'
    : /Firefox\//.test(userAgent) ? 'Firefox'
    : /Safari\//.test(userAgent) ? 'Safari'
    : 'Unknown browser'

  return `${browser} on ${os}`
}

/** "Kathmandu, Bagmati, Nepal" from whatever parts the login recorded; an em dash when none. */
export function locationLabel(row) {
  return [row.city, row.region, row.country].filter(Boolean).join(', ') || '—'
}
