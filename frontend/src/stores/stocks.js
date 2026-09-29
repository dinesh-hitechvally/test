import { defineStore } from 'pinia'
import client from '../api/client'
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
    async addStock(payload) {
      const data = await stocksApi.create(payload)
      this.stocks.push(data)
      return data
    },
    // A file upload, so this one stays a REST call.
    async importCsv(file, symbol) {
      const form = new FormData()
      form.append('file', file)
      if (symbol) form.append('symbol', symbol)
      const { data } = await client.post('/stocks/import-csv', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return data
    },
  },
})
