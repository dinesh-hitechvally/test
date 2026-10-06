import { computed, ref, watch } from 'vue'

/**
 * Which table columns are switched on — the "Screen Options" of a list page.
 *
 * `options` is the full list of columns ([{ key, label, locked? }]); a locked column
 * (e.g. Symbol) can't be switched off. What the user switches OFF is saved in the
 * browser (localStorage), so a column added later is on by default and a new visit
 * starts from the last choice.
 *
 *   const cols = useColumnOptions('stocks', [{ key: 'symbol', label: 'Symbol', locked: true }, …])
 *   cols.visibleKeys.value   // ['symbol', 'sector', …] — also what the page asks the API for
 *   cols.toggle('sector'); cols.reset()
 */
export function useColumnOptions(storageKey, options) {
  const key = `screen-options:${storageKey}`
  const known = new Set(options.map((o) => o.key))

  function load() {
    try {
      const saved = JSON.parse(localStorage.getItem(key) || '{}')
      return new Set((Array.isArray(saved.hidden) ? saved.hidden : []).filter((k) => known.has(k)))
    } catch {
      return new Set() // private window / blocked storage: everything on
    }
  }

  const hidden = ref(load())

  watch(hidden, (set) => {
    try {
      localStorage.setItem(key, JSON.stringify({ hidden: [...set] }))
    } catch {
      // not remembered, but still applies for this visit
    }
  })

  const visibleKeys = computed(() => options.filter((o) => o.locked || !hidden.value.has(o.key)).map((o) => o.key))
  const isVisible = (k) => visibleKeys.value.includes(k)
  const hiddenCount = computed(() => options.length - visibleKeys.value.length)

  function toggle(k) {
    if (options.find((o) => o.key === k)?.locked) return
    const next = new Set(hidden.value)
    if (next.has(k)) next.delete(k)
    else next.add(k)
    hidden.value = next
  }

  function reset() {
    hidden.value = new Set()
  }

  return { options, visibleKeys, isVisible, hiddenCount, toggle, reset }
}
