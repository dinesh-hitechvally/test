import { defineStore } from 'pinia'
import client from '../api/client'

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
        const { data } = await client.get('/stocks')
        this.stocks = data
      } finally {
        this.loading = false
      }
    },
    async fetchTodaySignals(signal = null) {
      const { data } = await client.get('/signals/today', { params: signal ? { signal } : {} })
      this.todaySignals = data
    },
    async fetchScrapeLogs() {
      const { data } = await client.get('/scrape/logs')
      this.scrapeLogs = data
    },
    async addStock(payload) {
      const { data } = await client.post('/stocks', payload)
      this.stocks.push(data)
      return data
    },
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
