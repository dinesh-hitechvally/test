import { defineStore } from 'pinia'
import * as portfolioApi from '../api/portfolio'

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
      const data = await portfolioApi.list()
      this.portfolios = data
      if (!this.activePortfolioId && data.length) {
        this.activePortfolioId = data[0].id
      }
    },
    async createPortfolio(name) {
      const data = await portfolioApi.create(name)
      this.portfolios.push({ ...data, summary: null })
      this.activePortfolioId = data.id
      return data
    },
    async fetchDetail(portfolioId) {
      this.loading = true
      try {
        this.detail = await portfolioApi.get(portfolioId)
      } finally {
        this.loading = false
      }
    },
    async fetchTransactions(portfolioId) {
      this.transactions = await portfolioApi.transactions(portfolioId)
    },
    async addTransaction(portfolioId, payload) {
      const data = await portfolioApi.addTransaction(portfolioId, payload)
      await Promise.all([this.fetchDetail(portfolioId), this.fetchTransactions(portfolioId)])
      return data
    },
    async deleteTransaction(portfolioId, transactionId) {
      await portfolioApi.deleteTransaction(portfolioId, transactionId)
      await Promise.all([this.fetchDetail(portfolioId), this.fetchTransactions(portfolioId)])
    },
    async setPositionTarget(portfolioId, stockId, payload) {
      const data = await portfolioApi.setPositionTarget(portfolioId, stockId, payload)
      await this.fetchDetail(portfolioId)
      return data
    },
  },
})
