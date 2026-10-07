import { onMounted, ref } from 'vue'
import { isApiOutage, reportApiError } from '../utils/apiActivity'

/**
 * Loads what a portfolio page needs and tracks how that went, so the page can show "loading", "could not load"
 * (with a Try again) or "no portfolio yet" instead of a spinner that never ends when the API is not answering.
 *
 *   const { loading, failed, load } = usePortfolioLoad(async () => {
 *     await store.fetchPortfolios()
 *     if (store.activePortfolioId) await store.fetchDetail(store.activePortfolioId)
 *   })
 *
 *   <PortfolioStatus v-if="loading || failed || !store.detail" :loading="loading" :failed="failed" @retry="load" />
 */
export function usePortfolioLoad(fetcher) {
  const loading = ref(true)
  const failed = ref(false)

  async function load() {
    loading.value = true
    failed.value = false

    try {
      await fetcher()
    } catch (e) {
      failed.value = true
      if (!isApiOutage(e)) throw e // a real bug still surfaces; an outage just becomes a notice
      reportApiError(e)
    } finally {
      loading.value = false
    }
  }

  onMounted(load)

  return { loading, failed, load }
}
