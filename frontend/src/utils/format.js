// Display helpers shared by every page — use these instead of per-page copies.

/** 1234.5 → "1,234.50", 1234567.5 → "12,34,567.50" (Nepali grouping, always 2 decimals); null/''/NaN → "—". */
export function formatPrice(value) {
  return formatNumber(value, { decimals: 2 })
}

/** A date and time as the person reads it, in their time zone (an IANA name such as Asia/Kathmandu; blank = this browser's). */
export function formatDateTime(value, timeZone = '') {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'

  try {
    return date.toLocaleString(undefined, timeZone ? { timeZone } : undefined)
  } catch {
    return date.toLocaleString() // an unknown zone name: fall back to the browser's
  }
}

/** "wait_confirmation" → "wait confirmation". */
export function formatSignal(signal) {
  return signal.replace('_', ' ')
}

/**
 * The CSS class for an up/down number: "positive" (green) above zero,
 * "negative" (red) below. Zero or missing → `neutral` ('' by default;
 * pass 'neutral' for StatCard's tone prop).
 */
export function changeTone(value, neutral = '') {
  if (value === null || value === undefined) return neutral
  return value > 0 ? 'positive' : value < 0 ? 'negative' : neutral
}

const SELL_REASON_LABELS = {
  stop_loss: 'Stop loss hit',
  trailing_stop: 'Trailing stop hit',
  target: 'Target reached',
  breakdown: 'Technical breakdown',
  signal_reversal: 'Signal reversal',
}

/** "trailing_stop" → "Trailing stop hit". */
export function formatSellReason(rule) {
  return SELL_REASON_LABELS[rule] ?? rule
}

/**
 * A number with thousands separators in the Nepali / Indian style: the last three digits, then pairs
 * (1234567 -> "12,34,567", 519999.5 -> "5,19,999.5"). Missing or non-numeric values show "—".
 * `decimals` fixes the number of decimal places; without it up to 3 are shown, as they come.
 */
export function formatNumber(value, { decimals } = {}) {
  if (value === null || value === undefined || value === '') return '—'

  const number = Number(value)
  if (Number.isNaN(number)) return '—'

  return number.toLocaleString('en-IN', decimals === undefined ? { maximumFractionDigits: 3 } : { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
}
