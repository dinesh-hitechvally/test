import { defineStore } from 'pinia'
import * as marketApi from '../api/market'
import * as stocksApi from '../api/stocks'

export const useStocksStore = defineStore('stocks', {
  state: () => ({
    stocks: [],
    todaySignals: [],
    scrapeLogs: [],
    loading: false,
  }),
  actions: {
    // Fetches the full stock list once; filtering/sorting/pagination happens
    // client-side (see Stocks.vue) so this list stays the single shared source
    // the topbar's global search also reads from.
    async fetchStocks() {
      this.loading = true
      try {
        this.stocks = await stocksApi.list()
      } finally {
        this.loading = false
      }
    },
    async fetchTodaySignals(signal = null) {
      this.todaySignals = await marketApi.todaySignals(signal)
    },
    async fetchScrapeLogs() {
      this.scrapeLogs = await marketApi.scrapeLogs()
    },
  },
})
