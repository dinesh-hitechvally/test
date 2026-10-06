import { onBeforeUnmount, ref, watchEffect } from 'vue'

/**
 * The top bar shows each page's title (the route's meta.title). A page whose title depends on its data — a stock
 * page showing "NABIL — Nabil Bank", a sector page showing the sector's name — sets it here instead of drawing its
 * own heading:
 *
 *   usePageTitle(() => (stock.value ? `${stock.value.symbol} — ${stock.value.company_name}` : ''))
 *
 * An empty string falls back to the route's title (e.g. while the data is still loading), and the override is
 * cleared when the page goes away so it can't leak onto the next page.
 */
export const pageTitleOverride = ref('')

export function usePageTitle(getTitle) {
  watchEffect(() => {
    pageTitleOverride.value = getTitle() || ''
  })

  onBeforeUnmount(() => {
    pageTitleOverride.value = ''
  })
}
