import { defineStore } from 'pinia'
import * as portfolioApi from '../api/portfolio'

export const usePortfolioStore = defineStore('portfolio', {
  state: () => ({
    portfolios: [],
    activePortfolioId: null,
    detail: null, // { portfolio, summary, holdings, realized }
    transactions: [],
    sellChecks: [], // sell/hold decision per holding
    buyOrders: [], // pending buy orders
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
    async fetchSellChecks(portfolioId) {
      this.sellChecks = await portfolioApi.sellChecks(portfolioId)
    },
    async fetchBuyOrders(portfolioId) {
      this.buyOrders = await portfolioApi.buyOrders(portfolioId, 'pending')
    },
    /** Holdings, their sell checks, transactions and pending orders — everything a ledger change can move. */
    async refreshAll(portfolioId) {
      await Promise.all([
        this.fetchDetail(portfolioId),
        this.fetchTransactions(portfolioId),
        this.fetchSellChecks(portfolioId),
        this.fetchBuyOrders(portfolioId),
      ])
    },
    // A buy fills its pending order and hands its stop/target to the position, so reload all of it.
    async addTransaction(portfolioId, payload) {
      const data = await portfolioApi.addTransaction(portfolioId, payload)
      await this.refreshAll(portfolioId)
      return data
    },
    async deleteTransaction(portfolioId, transactionId) {
      await portfolioApi.deleteTransaction(portfolioId, transactionId)
      await this.refreshAll(portfolioId)
    },
    async setPositionTarget(portfolioId, stockId, payload) {
      const data = await portfolioApi.setPositionTarget(portfolioId, stockId, payload)
      await Promise.all([this.fetchDetail(portfolioId), this.fetchSellChecks(portfolioId)])
      return data
    },
    async placeBuyOrder(portfolioId, symbol) {
      const order = await portfolioApi.placeBuyOrder(portfolioId, symbol)
      await this.fetchBuyOrders(portfolioId)
      return order
    },
    async cancelBuyOrder(portfolioId, orderId) {
      await portfolioApi.cancelBuyOrder(portfolioId, orderId)
      await this.fetchBuyOrders(portfolioId)
    },
    async setCash(portfolioId, amount) {
      const data = await portfolioApi.setCash(portfolioId, amount)
      const p = this.portfolios.find((x) => x.id === portfolioId)
      if (p) p.cash_balance = data.cash_balance
      if (this.detail?.portfolio?.id === portfolioId) this.detail.portfolio.cash_balance = data.cash_balance
      return data
    },
  },
})
