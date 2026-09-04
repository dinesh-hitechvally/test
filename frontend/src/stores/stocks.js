import { defineStore } from 'pinia'
import client from '../api/client'

export const useStocksStore = defineStore('stocks', {
  state: () => ({
    stocks: [],
    todaySignals: [],
    scrapeLogs: [],
    loading: false,
    lastError: null,
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
    // Official nepalstock.com source — see NepalStockScraperService. ShareSansar
    // (/scrape/run) is kept server-side only as a manual fallback, and as the
    // sole source for full-history backfills (the official API caps out at
    // ~1 year of history no matter what).
    async runScrape() {
      this.lastError = null
      try {
        const { data } = await client.post('/scrape/run-nepse')
        return data
      } catch (e) {
        this.lastError = e.response?.data?.message || 'Scrape failed.'
        throw e
      }
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
