import { computed, ref } from 'vue'

/**
 * Click-a-column-header sorting, shared by every data table in the app
 * instead of each view hand-rolling its own toggleSort/sortIndicator pair.
 *
 * @param {import('vue').Ref<any[]>|import('vue').ComputedRef<any[]>} rows
 * @param {{ defaultKey?: string|null, defaultDir?: 'asc'|'desc', valueGetters?: Record<string, (row: any) => any> }} options
 */
export function useSortableTable(rows, options = {}) {
  const { defaultKey = null, defaultDir = 'asc', valueGetters = {} } = options

  const sortKey = ref(defaultKey)
  const sortDir = ref(defaultDir)

  function valueOf(row, key) {
    const getter = valueGetters[key]
    const value = getter ? getter(row) : row?.[key]

    return value === undefined || value === null ? null : value
  }

  function toggleSort(key) {
    if (sortKey.value === key) {
      sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
      sortKey.value = key
      sortDir.value = 'asc'
    }
  }

  function sortIndicator(key) {
    if (sortKey.value !== key) return ''

    return sortDir.value === 'asc' ? '▲' : '▼'
  }

  const sorted = computed(() => {
    const list = rows.value ?? []
    if (!sortKey.value) return list

    const dir = sortDir.value === 'asc' ? 1 : -1

    return [...list].sort((a, b) => {
      const av = valueOf(a, sortKey.value)
      const bv = valueOf(b, sortKey.value)

      // Missing values always sort to the end regardless of direction —
      // "unknown" isn't meaningfully smaller or larger than a real value,
      // and flipping to the top on descending would read as "highest".
      if (av === null && bv === null) return 0
      if (av === null) return 1
      if (bv === null) return -1

      if (typeof av === 'string' && typeof bv === 'string') {
        return av.localeCompare(bv) * dir
      }

      if (av < bv) return -1 * dir
      if (av > bv) return 1 * dir

      return 0
    })
  })

  return { sorted, sortKey, sortDir, toggleSort, sortIndicator }
}
