// Display helpers shared by every page — use these instead of per-page copies.

/** 1234.5 → "1234.50"; null/''/NaN → "—". */
export function formatPrice(value) {
  if (value === null || value === undefined || value === '') return '—'
  const num = Number(value)
  return Number.isNaN(num) ? '—' : num.toFixed(2)
}

/** "strong_buy" → "strong buy". */
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
