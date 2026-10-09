// Option lists for the profile form: countries, Nepal's provinces, time zones and genders.

export const GENDERS = [
  { value: '', label: 'Select' },
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
]

// Nepal's seven provinces. Another country's states are free text — there is no list worth keeping for all of them.
export const NEPAL_PROVINCES = ['Koshi', 'Madhesh', 'Bagmati', 'Gandaki', 'Lumbini', 'Karnali', 'Sudurpashchim']

let countryCache = null

/** Every country name the browser knows (from its own region list), Nepal first, as dropdown options. */
export function countryOptions() {
  if (countryCache) return countryCache

  let names = []
  try {
    const display = new Intl.DisplayNames(['en'], { type: 'region' })
    const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
    for (const a of letters) {
      for (const b of letters) {
        const code = a + b
        let name
        try {
          name = display.of(code)
        } catch {
          continue
        }
        // An unassigned code comes back as itself.
        if (name && name !== code) names.push(name)
      }
    }
  } catch {
    names = ['Nepal', 'India', 'China', 'Bangladesh', 'Bhutan', 'Sri Lanka', 'Pakistan', 'Australia', 'United Kingdom', 'United States', 'Japan', 'South Korea', 'Qatar', 'United Arab Emirates', 'Malaysia', 'Saudi Arabia']
  }

  const sorted = [...new Set(names)].sort((x, y) => x.localeCompare(y))
  const rest = sorted.filter((n) => n !== 'Nepal')
  countryCache = [{ value: '', label: 'Select' }, { value: 'Nepal', label: 'Nepal' }, ...rest.map((n) => ({ value: n, label: n }))]

  return countryCache
}

/** The IANA time zones the browser supports, as dropdown options, Asia/Kathmandu first. */
export function timezoneOptions() {
  let zones = []
  try {
    zones = Intl.supportedValuesOf('timeZone')
  } catch {
    zones = ['Asia/Kathmandu', 'Asia/Kolkata', 'Asia/Dhaka', 'Asia/Dubai', 'Asia/Tokyo', 'Australia/Sydney', 'Europe/London', 'America/New_York', 'UTC']
  }

  const rest = zones.filter((z) => z !== 'Asia/Kathmandu')
  return [{ value: '', label: 'Select' }, { value: 'Asia/Kathmandu', label: 'Asia/Kathmandu' }, ...rest.map((z) => ({ value: z, label: z.replace(/_/g, ' ') }))]
}
