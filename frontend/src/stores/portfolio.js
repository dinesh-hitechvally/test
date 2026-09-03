import { defineStore } from 'pinia'
import client from '../api/client'

export const usePortfolioStore = defineStore('portfolio', {
  state: () => ({
    portfolios: [],
    activePortfolioId: null,
    detail: null, // { portfolio, summary, holdings, realized }
    transactions: [],
    loading: false,
  }),
  actions: {
    async fetchPortfolios() {
      const { data } = await client.get('/portfolios')
      this.portfolios = data
      if (!this.activePortfolioId && data.length) {
        this.activePortfolioId = data[0].id
      }
    },
    async createPortfolio(name) {
      const { data } = await client.post('/portfolios', { name })
      this.portfolios.push({ ...data, summary: null })
      this.activePortfolioId = data.id
      return data
    },
    async fetchDetail(portfolioId) {
      this.loading = true
      try {
        const { data } = await client.get(`/portfolios/${portfolioId}`)
        this.detail = data
      } finally {
        this.loading = false
      }
    },
    async fetchTransactions(portfolioId) {
      const { data } = await client.get(`/portfolios/${portfolioId}/transactions`)
      this.transactions = data
    },
    async addTransaction(portfolioId, payload) {
      const { data } = await client.post(`/portfolios/${portfolioId}/transactions`, payload)
      await Promise.all([this.fetchDetail(portfolioId), this.fetchTransactions(portfolioId)])
      return data
    },
    async deleteTransaction(portfolioId, transactionId) {
      await client.delete(`/portfolios/${portfolioId}/transactions/${transactionId}`)
      await Promise.all([this.fetchDetail(portfolioId), this.fetchTransactions(portfolioId)])
    },
  },
})
