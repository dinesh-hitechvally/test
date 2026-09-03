export function formatPrice(value) {
  if (value === null || value === undefined || value === '') return '—'
  const num = Number(value)
  return Number.isNaN(num) ? '—' : num.toFixed(2)
}
